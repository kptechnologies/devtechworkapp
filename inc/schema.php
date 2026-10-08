<?php
/**
 * Daily Staff Work Progress Report.
 * Used for: rendering the wizard (sent to the browser), server-side validation, CSV import/export.
 *
 * Staff tick what they did today ("duties") and only the matching sections are asked.
 *
 * Section keys: id, title, icon, show (section is skipped unless every condition passes), fields.
 * Field keys: id, label, type (text|textarea|number|date|time|select|radio|checks|confirm|jobs|rows),
 * req, opts (array, '@locations', '@devices' or '@faults'), show (list of [fieldId, op, value] — all must pass),
 * ph (placeholder), help, h (original sheet header when it differs from label), cols (for 'rows').
 * Operators: eq, ne, gt, nempty, has (checks field contains value), hasany (contains any of a list).
 */

const YES_NO = ['Yes', 'No'];

/** "What did you do today?" Each duty switches on its own report sections. */
const DUTY_CLASS = 'Class / lesson delivery';
const DUTY_GC = 'Google Classroom / content prep';
const DUTY_PROJECT = 'Project work';
const DUTY_INSTALL = 'Installation';
const DUTY_MAINT = 'Maintenance & repair';
const DUTY_TRAINING = 'Training';
const DUTY_MEDIA = 'Media editing';
const DUTY_OFFICE = 'Office / admin work';
const DUTIES = [DUTY_CLASS, DUTY_GC, DUTY_PROJECT, DUTY_INSTALL, DUTY_MAINT, DUTY_TRAINING, DUTY_MEDIA, DUTY_OFFICE];

const ISSUE_PRIORITIES = ['Normal', 'High', 'Urgent'];

const DEVICE_STATUS =['Fixed', 'Partially fixed', 'Pending – needs parts', 'Pending – needs follow-up', 'Could not fix'];

/** Questions from the first version of the form: still mapped on CSV import and used to read old reports. */
const LEGACY_REPORT_FIELDS = [
    'work_type' => "Today's Work Type",
    'lesson'    => 'Did you deliver any Coding/Robotics lesson today?',
    'gc'        => 'Did you work on Google Classroom today?',
    'install'   => 'Did you carry out any installation or maintenance today?',
    'video'     => 'Did you edit any video today?',
    'photopea'  => 'Did you edit any images today using Photopea?',
];

/** Devices and their fault checklist for the maintenance form. Admins can edit this in Settings. */
function default_fault_types(): array
{
    return [
        'Laptop' => ["Won't power on", 'Not charging / charger fault', 'Battery drains fast', "Operating system won't boot",
            'OS reinstall / Windows update needed', 'RAM fault / upgrade', 'Hard drive / SSD fault', 'Slow performance',
            'Screen / display fault', 'Keyboard / touchpad fault', 'Virus / malware', 'Software install (Scratch/Arduino IDE/drivers)',
            'Wi-Fi / network adapter', 'Other'],
        'Desktop computer' => ["Won't power on", "Operating system won't boot", 'OS reinstall / update needed', 'RAM fault / upgrade',
            'Hard drive / SSD fault', 'Monitor / display', 'Keyboard / mouse', 'Slow performance', 'Virus / malware', 'Software install', 'Other'],
        'Socket / power outlet' => ['No power', 'Loose socket', 'Burnt / damaged socket', 'Faulty wiring', 'Extension box fault',
            'Breaker keeps tripping', 'New socket installed', 'Other'],
        'Projector' => ['No display', 'Blurry / dim image', 'Lamp fault', 'Overheating', 'Cable / HDMI issue', 'Remote control', 'Mounting / alignment', 'Other'],
        'Interactive board' => ['Touch not responding', 'Calibration needed', 'Driver / software issue', 'No display', 'Pen / stylus fault', 'Mounting', 'Other'],
        'Inverter / UPS' => ['Error / alarm', 'Low battery / battery fault', 'Not charging', 'Overload', 'Wiring / connection', 'Configuration', 'Other'],
        'Router / network' => ['No internet', 'Slow internet', 'MiFi / data finished', 'Router configuration', 'Cabling / trunking', 'Access point fault', 'Other'],
        'Other device' => ['Other'],
    ];
}

function fault_types(): array
{
    $t = setting('fault_types');
    return is_array($t) && $t ? $t : default_fault_types();
}

function report_schema(): array
{
    $has = fn(string $duty) => [['duties', 'has', $duty]];
    $faulty = [['laptops_faulty', 'gt', 0]];
    $complaint = [['complaint', 'ne', 'No complaint'], ['complaint', 'nempty', '']];
    $follow = [['followup', 'ne', 'No'], ['followup', 'nempty', '']];
    $tools = [['tools_used', 'eq', 'Yes']];
    $video = [['media_done', 'has', 'Video editing']];
    $pp = [['media_done', 'has', 'Image editing (Photopea)']];

    return [
        ['id' => 'day', 'title' => 'Your day', 'icon' => 'calendar', 'fields' => [
            ['id' => 'location', 'label' => 'Assigned Location / School', 'type' => 'select', 'req' => true, 'opts' => '@locations'],
            ['id' => 'report_date', 'label' => 'Date of Report', 'type' => 'date', 'req' => true],
            ['id' => 'duties', 'label' => 'What did you do today? (tick all that apply)', 'type' => 'checks', 'req' => true, 'opts' => DUTIES,
                'help' => 'Only the questions for the work you tick will be asked.'],
            ['id' => 'jobs_worked', 'label' => 'Which job orders did you work on today?', 'type' => 'jobs'],
            ['id' => 'check_in', 'label' => 'Time checked in to work (HH:MM)', 'type' => 'time', 'req' => true],
            ['id' => 'check_out', 'label' => 'Time closed for work (HH:MM)', 'type' => 'time', 'req' => true],
            ['id' => 'finished', 'label' => "Did you finish today's assigned tasks?", 'type' => 'radio', 'req' => true, 'opts' => [
                'Yes – completed', 'Partially completed', 'No – not completed']],
            ['id' => 'pending_reason', 'label' => 'If Partially/No: what is pending, why, and when will you complete it?', 'type' => 'textarea', 'req' => true,
                'show' => [['finished', 'ne', 'Yes – completed'], ['finished', 'nempty', '']]],
        ]],

        ['id' => 'lessons', 'title' => 'Lessons and classroom', 'icon' => 'school', 'show' => $has(DUTY_CLASS), 'fields' => [
            ['id' => 'classes_taught', 'label' => 'Number of classes taught today (enter a number)', 'type' => 'number', 'req' => true],
            ['id' => 'classes_list', 'label' => 'List classes taught (e.g., JSS1 A Coding, Primary 5 Robotics)', 'type' => 'text', 'ph' => 'JSS1 A Coding, Primary 5 Robotics'],
            ['id' => 'topics', 'label' => 'Topics covered today (brief)', 'type' => 'textarea'],
            ['id' => 'lesson_tools', 'label' => 'Tools/Platforms used in class (tick all that apply)', 'type' => 'checks', 'opts' => [
                'Scratch', 'MIT App Inventor', 'Arduino', 'Robotics Kits (motors/sensors)', 'Google Classroom', 'Other (specify in remarks)']],
            ['id' => 'lesson_outcome', 'label' => 'Lesson outcome today', 'type' => 'radio', 'req' => true, 'opts' => [
                'Successful (objectives met)', 'Partially successful', 'Not successful (explain in remarks)']],
            ['id' => 'lesson_drive', 'label' => 'COMPULSORY: Drive folder link for today’s lesson video(s)', 'type' => 'text', 'req' => true, 'ph' => 'https://drive.google.com/...'],
            ['id' => 'lesson_videos', 'label' => 'Lesson video link(s) (paste individual video link(s) if available)', 'type' => 'textarea'],
        ]],

        ['id' => 'laptops', 'title' => 'Computer lab check', 'icon' => 'device-laptop', 'show' => $has(DUTY_CLASS), 'fields' => [
            ['id' => 'laptops_total', 'label' => 'Total laptops available at your location today (number)', 'type' => 'number'],
            ['id' => 'laptops_ok', 'label' => 'Number of functional laptops today (number)', 'type' => 'number'],
            ['id' => 'laptops_faulty', 'label' => 'Number of faulty/spoiled laptops today (number)', 'type' => 'number'],
            ['id' => 'faulty_details', 'label' => 'Faulty laptop details (IMPORTANT)', 'type' => 'textarea', 'req' => true, 'show' => $faulty, 'ph' => 'Laptop ID, fault, since when'],
            ['id' => 'laptop_issues', 'label' => 'Laptop/Connectivity Issues Checklist (tick all that apply)', 'type' => 'checks', 'opts' => [
                'Laptop not charging', 'Laptop charger missing/faulty', 'Battery drains fast', 'Windows/software issue',
                'No Wi-Fi at location', 'Slow internet affecting work', 'MiFi/Data finished', 'Other (state below)']],
            ['id' => 'laptop_issue_other', 'label' => 'Other laptop/Wi-Fi/data issue (if any)', 'type' => 'text'],
            ['id' => 'laptop_resolved', 'label' => 'Was any faulty laptop resolved today?', 'type' => 'radio', 'show' => $faulty, 'opts' => [
                'Yes – fixed', 'Partially resolved', 'No – pending']],
            ['id' => 'laptop_action', 'label' => 'Action taken / next step for faulty laptops & connectivity', 'type' => 'textarea', 'show' => $faulty],
            ['id' => 'tech_issues', 'label' => 'Technical issues encountered today (tick all that apply)', 'type' => 'checks', 'opts' => [
                'Projector issue (no display/blur/dim)', 'Interactive board not responding / touch issue', 'Interactive board calibration needed',
                'Inverter issue (error/alarm/low battery)', 'Power outlet/socket extension issue', 'Network/Wi-Fi not stable',
                'Need data / MiFi issue', 'Laptop OS update/reinstallation needed',
                'Software installation pending (Scratch/App Inventor/Arduino IDE/Drivers)', 'Other (state below)']],
            ['id' => 'tech_describe', 'label' => 'Describe technical issue(s) clearly (include device ID, error code, screenshots if any)', 'type' => 'textarea'],
            ['id' => 'tech_evidence', 'label' => 'Evidence link for technical issue(s) (photo/video/screenshot folder)', 'type' => 'text'],
        ]],

        ['id' => 'classroom', 'title' => 'Google Classroom', 'icon' => 'brand-google', 'show' => $has(DUTY_GC), 'fields' => [
            ['id' => 'gc_classes', 'label' => 'Number of classes prepared today (enter a number)', 'type' => 'number', 'req' => true],
            ['id' => 'gc_list', 'label' => 'List class names prepared (e.g., JSS1 ICT, Primary 5 Coding)', 'type' => 'text'],
            ['id' => 'gc_done', 'label' => 'For today’s classes, confirm what was done (tick all that apply)', 'type' => 'checks', 'req' => true, 'opts' => [
                'Lesson material uploaded', 'Assignment created', 'Both lesson & assignment', 'Not completed']],
            ['id' => 'gc_links', 'label' => 'Google Classroom link(s) (paste class/assignment links)', 'type' => 'textarea'],
        ]],

        ['id' => 'projects', 'title' => 'Projects', 'icon' => 'bulb', 'show' => $has(DUTY_PROJECT), 'fields' => [
            ['id' => 'projects', 'label' => 'Number of projects worked on today (enter a number)', 'type' => 'number', 'req' => true],
            ['id' => 'project_titles', 'label' => 'Project title(s) + short description', 'type' => 'textarea', 'req' => true],
            ['id' => 'project_progress', 'label' => 'Project progress', 'type' => 'radio', 'opts' => ['Started', 'In progress', 'Completed', 'Blocked (explain in remarks)']],
            ['id' => 'project_link', 'label' => 'Project upload location / link(s) (Drive folder or Classroom link)', 'type' => 'text'],
        ]],

        ['id' => 'install', 'title' => 'Installation', 'icon' => 'tools', 'show' => $has(DUTY_INSTALL), 'fields' => [
            ['id' => 'install_type', 'label' => 'What did you install? (tick all that apply)', 'type' => 'checks', 'req' => true,
                'h' => 'Type of work carried out (tick all that apply)', 'opts' => [
                'Projector installation', 'Interactive Board installation', 'Interactive Board calibration / setup',
                'Inverter / UPS installation', 'Sockets / power points', 'Network / router / cabling', 'Laptop / computer setup',
                'Software installation', 'Other (specify in remarks)']],
            ['id' => 'install_location', 'label' => 'Installation location / school (if different from above)', 'type' => 'text',
                'h' => 'Installation location / school (if applicable)'],
            ['id' => 'install_items', 'label' => 'Number of items installed (number)', 'type' => 'number', 'req' => true,
                'h' => 'Number of items installed / worked on (number)'],
            ['id' => 'install_desc', 'label' => 'Brief description of work done', 'type' => 'textarea', 'req' => true],
            ['id' => 'install_done', 'label' => 'Was the work completed successfully?', 'type' => 'radio', 'req' => true, 'opts' => [
                'Yes', 'Partially', 'No (explain in remarks)']],
            ['id' => 'install_evidence', 'label' => 'Evidence link (photos/videos/Drive folder)', 'type' => 'text'],
        ]],

        ['id' => 'maint', 'title' => 'Maintenance and repair', 'icon' => 'settings-cog', 'show' => $has(DUTY_MAINT), 'fields' => [
            ['id' => 'devices', 'label' => 'Devices you worked on', 'type' => 'rows', 'req' => true, 'add' => 'Add another device',
                'help' => 'One entry per laptop, socket, projector and so on. Attach before/after photos on the last step.', 'cols' => [
                    ['id' => 'device', 'label' => 'Device', 'type' => 'select', 'req' => true, 'opts' => '@devices'],
                    ['id' => 'tag', 'label' => 'Device ID / tag / where', 'type' => 'text', 'ph' => 'e.g. LAP-014, Lab 2 socket 3'],
                    ['id' => 'faults', 'label' => 'Fault(s) found', 'type' => 'checks', 'req' => true, 'opts' => '@faults', 'by' => 'device'],
                    ['id' => 'action', 'label' => 'What you did', 'type' => 'textarea', 'req' => true, 'ph' => 'e.g. Replaced 4GB RAM with 8GB, reinstalled Windows 10'],
                    ['id' => 'parts', 'label' => 'Parts replaced / used', 'type' => 'text', 'ph' => 'e.g. 8GB DDR4 RAM, 13A socket'],
                    ['id' => 'cost', 'label' => 'Parts cost (if any)', 'type' => 'number'],
                    ['id' => 'status', 'label' => 'Status', 'type' => 'radio', 'req' => true, 'opts' => DEVICE_STATUS],
                    ['id' => 'priority', 'label' => 'How urgent is it if not fixed?', 'type' => 'radio', 'opts' => ISSUE_PRIORITIES],
                ]],
            ['id' => 'maint_notes', 'label' => 'Anything else about today’s maintenance?', 'type' => 'textarea'],
        ]],

        ['id' => 'tools', 'title' => 'Tools used', 'icon' => 'hammer', 'show' => [['duties', 'hasany', [DUTY_INSTALL, DUTY_MAINT]]], 'fields' => [
            ['id' => 'tools_used', 'label' => 'Did you use any office / installation tools today?', 'type' => 'radio', 'req' => true, 'opts' => YES_NO],
            ['id' => 'tools_list', 'label' => 'List of tools used (tick all that apply)', 'type' => 'checks', 'show' => $tools, 'opts' => [
                'Drill machine', 'Drill bits', 'Screwdriver set', 'Hammer', 'Allen keys', 'Trunking tools', 'Multimeter',
                'Cable ties', 'Laptop (for setup/configuration)', 'Other (specify below)']],
            ['id' => 'tools_other', 'label' => 'Other tools used (if any)', 'type' => 'text', 'show' => $tools],
            ['id' => 'tools_returned', 'label' => 'Did you pack and return all tools after use?', 'type' => 'radio', 'req' => true, 'show' => $tools, 'opts' => [
                'Yes – all tools accounted for', 'Partially – some tools still pending', 'No – tools missing (explain)']],
            ['id' => 'tools_missing', 'label' => 'If any tool is missing or damaged, explain clearly', 'type' => 'textarea',
                'show' => [['tools_used', 'eq', 'Yes'], ['tools_returned', 'ne', 'Yes – all tools accounted for'], ['tools_returned', 'nempty', '']]],
            ['id' => 'tools_confirm', 'label' => 'Tool handling confirmation (Required)', 'type' => 'confirm', 'req' => true, 'show' => $tools,
                'text' => 'I confirm all tools listed were properly packed and returned, or I stated any exceptions.'],
        ]],

        ['id' => 'training', 'title' => 'Training', 'icon' => 'presentation', 'show' => $has(DUTY_TRAINING), 'fields' => [
            ['id' => 'training_role', 'label' => 'Did you attend or deliver the training?', 'type' => 'radio', 'req' => true, 'opts' => [
                'Attended a training', 'Delivered a training']],
            ['id' => 'training_topic', 'label' => 'Training topic', 'type' => 'text', 'req' => true],
            ['id' => 'training_by', 'label' => 'Trainer / organiser', 'type' => 'text'],
            ['id' => 'training_people', 'label' => 'Number of participants', 'type' => 'number',
                'show' => [['training_role', 'eq', 'Delivered a training']]],
            ['id' => 'training_notes', 'label' => 'Key things learnt or covered', 'type' => 'textarea'],
        ]],

        ['id' => 'media', 'title' => 'Media', 'icon' => 'movie', 'show' => $has(DUTY_MEDIA), 'fields' => [
            ['id' => 'media_done', 'label' => 'What did you edit today?', 'type' => 'checks', 'req' => true, 'opts' => ['Video editing', 'Image editing (Photopea)']],
            ['id' => 'video_tool', 'label' => 'Video editing tool used', 'type' => 'radio', 'show' => $video, 'opts' => [
                'Google Vids (Preferred)', 'Clipchamp', 'Other (must have no watermark)']],
            ['id' => 'video_count', 'label' => 'Number of videos edited today (enter a number)', 'type' => 'number', 'show' => $video],
            ['id' => 'video_link', 'label' => 'Link to finished video folder (Drive link required)', 'type' => 'text', 'req' => true, 'show' => $video],
            ['id' => 'photopea_count', 'label' => 'Number of images edited (enter a number)', 'type' => 'number', 'show' => $pp],
            ['id' => 'photopea_link', 'label' => 'Link to edited images folder (Drive link)', 'type' => 'text', 'show' => $pp],
        ]],

        ['id' => 'office', 'title' => 'Office work', 'icon' => 'building', 'show' => $has(DUTY_OFFICE), 'fields' => [
            ['id' => 'office_tasks', 'label' => 'What office / admin work did you do today?', 'type' => 'textarea', 'req' => true,
                'ph' => 'e.g. Lesson planning for next week, inventory count, meetings'],
        ]],

        ['id' => 'people', 'title' => 'Complaints and follow-up', 'icon' => 'message-report', 'fields' => [
            ['id' => 'complaint', 'label' => 'Was there any complaint today?', 'type' => 'radio', 'req' => true, 'opts' => [
                'No complaint', 'Yes – Teacher complaint', 'Yes – School Director complaint','Yes – Other (specify in remarks)']],
            ['id' => 'complaint_severity', 'label' => 'Complaint severity', 'type' => 'radio', 'show' => $complaint, 'opts' => ['Low', 'Medium', 'High']],
            ['id' => 'complaint_details', 'label' => 'Complaint details (who, what happened, time, class)', 'type' => 'textarea', 'req' => true, 'show' => $complaint],
            ['id' => 'complaint_handled', 'label' => 'How was it handled today? (resolution/response)', 'type' => 'textarea', 'show' => $complaint],
            ['id' => 'complaint_evidence', 'label' => 'Evidence link (screenshots/photos/messages) - if any', 'type' => 'text', 'show' => $complaint],
            ['id' => 'followup', 'label' => 'Is any follow-up needed with the School Director / Teachers / DevTech team today?', 'type' => 'radio', 'req' => true, 'opts' => [
                'No', 'Yes – School Director', 'Yes – Teachers', 'Yes – DevTech Office/Team', 'Yes – Multiple (explain below)']],
            ['id' => 'followup_details', 'label' => 'Follow-up details (pending items + who to follow up with)', 'type' => 'textarea', 'req' => true, 'show' => $follow],
            ['id' => 'followup_plan', 'label' => 'Next action plan + expected date/time for follow-up', 'type' => 'textarea', 'show' => $follow],
        ]],

        ['id' => 'wrap', 'title' => 'Wrap-up', 'icon' => 'checklist', 'fields' => [
            ['id' => 'pending_tasks', 'label' => 'List pending tasks to do (clear bullet points)', 'type' => 'textarea'],
            ['id' => 'issue_priority', 'label' => 'How urgent are today’s open issues or pending tasks?', 'type' => 'radio', 'opts' => ISSUE_PRIORITIES,
                'help' => 'High and Urgent are emailed to the office straight away so they can follow up.'],
            ['id' => 'urgent', 'label' => 'Any urgent item requiring management approval/funds?', 'type' => 'radio', 'req' => true, 'opts' => ['No', 'Yes (state clearly below)']],
            ['id' => 'urgent_request', 'label' => 'If YES, state the urgent request clearly (what is needed + cost if known)', 'type' => 'textarea', 'req' => true,
                'show' => [['urgent', 'eq', 'Yes (state clearly below)']]],
            ['id' => 'challenges', 'label' => 'Any challenges encountered today? (Be specific)', 'type' => 'textarea'],
            ['id' => 'remarks', 'label' => 'Other remarks / clarifications', 'type' => 'textarea'],
            ['id' => 'final_confirm', 'label' => 'Final Confirmation (Required)', 'type' => 'confirm', 'req' => true,
                'text' => 'I confirm that the information provided above is true and accurate.'],
        ]],
    ];
}

/**
 * Reports saved before duties existed: work out what the person did from the old Yes/No questions,
 * so the right sections show when the report is viewed or edited.
 */
function report_normalize(array $d): array
{
    if (!empty($d['duties']) && is_array($d['duties'])) {
        return $d;
    }
    $duties = [];
    $wt = (string)($d['work_type'] ?? '');
    if (($d['lesson'] ?? '') === 'Yes' || $wt === 'School Lesson Delivery') {
        $duties[] = DUTY_CLASS;
    }
    if (($d['gc'] ?? '') === 'Yes') {
        $duties[] = DUTY_GC;
    }
    if (is_numeric($d['projects'] ?? '') && (float)$d['projects'] > 0) {
        $duties[] = DUTY_PROJECT;
    }
    if (($d['install'] ?? '') === 'Yes' || $wt === 'Installation / Maintenance') {
        $duties[] = DUTY_INSTALL;
    }
    $media = [];
    if (($d['video'] ?? '') === 'Yes') {
        $media[] = 'Video editing';
    }
    if (($d['photopea'] ?? '') === 'Yes') {
        $media[] = 'Image editing (Photopea)';
    }
    if ($media) {
        $duties[] = DUTY_MEDIA;
        $d['media_done'] = $d['media_done'] ?? $media;
    }
    if ($wt === 'Office Content / Planning') {
        $duties[] = DUTY_OFFICE;
    }
    $d['duties'] = array_values(array_unique($duties));
    return $d;
}

/** Flat list of fields with their options resolved. A field inherits its section's show conditions. */
function report_fields(): array
{
    static $flat = null;
    if ($flat === null) {
        $flat = [];
        foreach (report_schema_resolved() as $sec) {
            foreach ($sec['fields'] as $f) {
                $f['section'] = $sec['id'];
                $f['show'] = array_merge($sec['show'] ?? [], $f['show'] ?? []);
                $flat[$f['id']] = $f;
            }
        }
    }
    return $flat;
}

function report_schema_resolved(): array
{
    $schema = report_schema();
    foreach ($schema as &$sec) {
        foreach ($sec['fields'] as &$f) {
            if (($f['opts'] ?? null) === '@locations') {
                $f['opts'] = location_options();
            }
            foreach ($f['cols'] ?? [] as $i => $c) {
                if (($c['opts'] ?? null) === '@devices') {
                    $f['cols'][$i]['opts'] = array_keys(fault_types());
                }
            }
        }
        unset($f);
    }
    return $schema;
}

function field_visible(array $f, array $data): bool
{
    foreach ($f['show'] ?? [] as [$fid, $op, $val]) {
        $raw = $data[$fid] ?? '';
        $list = is_array($raw) ? array_map('strval', $raw) : [];
        $v = is_array($raw) ? implode(', ', $raw) : (string)$raw;
        $ok = match ($op) {
            'eq'     => $v === (string)$val,
            'ne'     => $v !== (string)$val,
            'gt'     => is_numeric($v) && (float)$v > (float)$val,
            'nempty' => $v !== '',
            'has'    => in_array((string)$val, $list, true),
            'hasany' => (bool)array_intersect(array_map('strval', (array)$val), $list),
            default  => true,
        };
        if (!$ok) {
            return false;
        }
    }
    return true;
}

/** Clean the repeatable entries of a 'rows' field (devices worked on). Returns [rows, error|null]. */
function clean_rows(array $f, $raw): array
{
    $rows = [];
    foreach (array_slice(is_array($raw) ? $raw : [], 0, 30) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $row = [];
        foreach ($f['cols'] as $c) {
            $v = $r[$c['id']] ?? null;
            $row[$c['id']] = match ($c['type']) {
                'checks' => array_values(array_filter(array_map(fn($x) => str_in($x, 200), is_array($v) ? $v : []), 'strlen')),
                'number' => is_numeric($v) ? 0 + $v : '',
                default  => str_in($v, $c['type'] === 'textarea' ? 2000 : 300),
            };
        }
        if (array_filter($row, fn($x) => $x !== '' && $x !== [])) {
            $rows[] = $row;
        }
    }
    foreach ($rows as $i => $row) {
        foreach ($f['cols'] as $c) {
            if (!empty($c['req']) && ($row[$c['id']] === '' || $row[$c['id']] === [])) {
                return [$rows, 'Device ' . ($i + 1) . ': fill in "' . $c['label'] . '".'];
            }
        }
    }
    return [$rows, null];
}

/** Validate and clean submitted report answers. Returns [cleanData, errors]. */
function clean_report(array $in): array
{
    $out = [];
    $errors = [];
    foreach (report_fields() as $id => $f) {
        $raw = $in[$id] ?? null;
        switch ($f['type']) {
            case 'rows':
                [$val, $rowErr] = clean_rows($f, $raw);
                if ($rowErr) {
                    $errors[$id] = $rowErr;
                }
                break;
            case 'checks':
            case 'jobs':
                $vals = is_array($raw) ? $raw : (($raw === null || $raw === '') ? [] : [$raw]);
                $val = array_values(array_filter(array_map(fn($x) => str_in($x, 300), $vals), 'strlen'));
                break;
            case 'number':
                $val = ($raw === null || $raw === '') ? '' : (is_numeric($raw) ? 0 + $raw : '');
                if ($raw !== null && $raw !== '' && !is_numeric($raw)) {
                    $errors[$id] = 'Enter a number.';
                }
                break;
            case 'date':
                $val = str_in($raw, 10);
                if ($val !== '' && !valid_date($val)) {
                    $errors[$id] = 'Enter a valid date.';
                }
                break;
            case 'time':
                $val = str_in($raw, 5);
                if ($val !== '' && !preg_match('/^\d{2}:\d{2}$/', $val)) {
                    $errors[$id] = 'Enter a valid time.';
                }
                break;
            case 'confirm':
                $val = ($raw && $raw !== 'false') ? ($f['text'] ?? 'Yes') : '';
                break;
            default:
                // Options aren't enforced strictly so imported/historical answers survive edits.
                $val = str_in($raw, $f['type'] === 'textarea' ? 5000 : 1000);
        }
        $out[$id] = $val;
    }
    foreach (report_fields() as $id => $f) {
        $visible = field_visible($f, $out);
        if (!$visible) {
            $out[$id] = in_array($f['type'], ['checks', 'jobs', 'rows'], true) ? [] : '';
            unset($errors[$id]);
            continue;
        }
        $empty = $out[$id] === '' || $out[$id] === [];
        if (!empty($f['req']) && $empty && !isset($errors[$id])) {
            $errors[$id] = $f['type'] === 'rows' ? 'Add at least one device.' : 'This is required.';
        }
    }
    return [$out, $errors];
}

function norm_header(string $s): string
{
    $s = str_replace(['’', '‘', '–', '—'], ["'", "'", '-', '-'], $s);
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}
