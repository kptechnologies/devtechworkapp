<?php
/**
 * Daily Staff Work Progress Report — mirrors the original Google Form questions.
 * Used for: rendering the wizard (sent to the browser), server-side validation, CSV import/export.
 *
 * Field keys: id, label, type (text|textarea|number|date|time|select|radio|checks|confirm),
 * req, opts (array or '@locations'), show (list of [fieldId, op, value] — all must pass),
 * ph (placeholder), h (original sheet header when it differs from label).
 */

const YES_NO = ['Yes', 'No'];

function report_schema(): array
{
    $lesson = [['lesson', 'eq', 'Yes']];
    $gc = [['gc', 'eq', 'Yes']];
    $faulty = [['laptops_faulty', 'gt', 0]];
    $complaint = [['complaint', 'ne', 'No complaint'], ['complaint', 'nempty', '']];
    $follow = [['followup', 'ne', 'No'], ['followup', 'nempty', '']];
    $install = [['install', 'eq', 'Yes']];
    $tools = [['tools_used', 'eq', 'Yes']];
    $video = [['video', 'eq', 'Yes']];
    $pp = [['photopea', 'eq', 'Yes']];

    return [
        ['id' => 'day', 'title' => 'Your day', 'icon' => 'calendar', 'fields' => [
            ['id' => 'location', 'label' => 'Assigned Location / School', 'type' => 'select', 'req' => true, 'opts' => '@locations'],
            ['id' => 'report_date', 'label' => 'Date of Report', 'type' => 'date', 'req' => true],
            ['id' => 'work_type', 'label' => "Today's Work Type", 'type' => 'radio', 'req' => true, 'opts' => [
                'School Lesson Delivery', 'Office Content / Planning', 'Installation / Maintenance', 'Combination (Lesson + other tasks)']],
            ['id' => 'check_in', 'label' => 'Time checked in to work (HH:MM)', 'type' => 'time', 'req' => true],
            ['id' => 'check_out', 'label' => 'Time closed for work (HH:MM)', 'type' => 'time', 'req' => true],
            ['id' => 'finished', 'label' => "Did you finish today's assigned tasks?", 'type' => 'radio', 'req' => true, 'opts' => [
                'Yes – completed', 'Partially completed', 'No – not completed']],
            ['id' => 'pending_reason', 'label' => 'If Partially/No: what is pending, why, and when will you complete it?', 'type' => 'textarea', 'req' => true,
                'show' => [['finished', 'ne', 'Yes – completed'], ['finished', 'nempty', '']]],
        ]],

        ['id' => 'lessons', 'title' => 'Lessons and classroom', 'icon' => 'school', 'fields' => [
            ['id' => 'lesson', 'label' => 'Did you deliver any Coding/Robotics lesson today?', 'type' => 'radio', 'req' => true, 'opts' => YES_NO],
            ['id' => 'classes_taught', 'label' => 'Number of classes taught today (enter a number)', 'type' => 'number', 'req' => true, 'show' => $lesson],
            ['id' => 'classes_list', 'label' => 'List classes taught (e.g., JSS1 A Coding, Primary 5 Robotics)', 'type' => 'text', 'show' => $lesson, 'ph' => 'JSS1 A Coding, Primary 5 Robotics'],
            ['id' => 'topics', 'label' => 'Topics covered today (brief)', 'type' => 'textarea', 'show' => $lesson],
            ['id' => 'lesson_tools', 'label' => 'Tools/Platforms used in class (tick all that apply)', 'type' => 'checks', 'show' => $lesson, 'opts' => [
                'Scratch', 'MIT App Inventor', 'Arduino', 'Robotics Kits (motors/sensors)', 'Google Classroom', 'Other (specify in remarks)']],
            ['id' => 'lesson_outcome', 'label' => 'Lesson outcome today', 'type' => 'radio', 'req' => true, 'show' => $lesson, 'opts' => [
                'Successful (objectives met)', 'Partially successful', 'Not successful (explain in remarks)']],
            ['id' => 'lesson_drive', 'label' => 'COMPULSORY: Drive folder link for today’s lesson video(s)', 'type' => 'text', 'req' => true, 'show' => $lesson, 'ph' => 'https://drive.google.com/...'],
            ['id' => 'lesson_videos', 'label' => 'Lesson video link(s) (paste individual video link(s) if available)', 'type' => 'textarea', 'show' => $lesson],
            ['id' => 'gc', 'label' => 'Did you work on Google Classroom today?', 'type' => 'radio', 'req' => true, 'opts' => YES_NO],
            ['id' => 'gc_classes', 'label' => 'Number of classes prepared today (enter a number)', 'type' => 'number', 'show' => $gc],
            ['id' => 'gc_list', 'label' => 'List class names prepared (e.g., JSS1 ICT, Primary 5 Coding)', 'type' => 'text', 'show' => $gc],
            ['id' => 'gc_done', 'label' => 'For today’s classes, confirm what was done (tick all that apply)', 'type' => 'checks', 'show' => $gc, 'opts' => [
                'Lesson material uploaded', 'Assignment created', 'Both lesson & assignment', 'Not completed']],
            ['id' => 'gc_links', 'label' => 'Google Classroom link(s) (paste class/assignment links)', 'type' => 'textarea', 'show' => $gc],
            ['id' => 'projects', 'label' => 'Number of projects worked on today (enter a number)', 'type' => 'number'],
            ['id' => 'project_titles', 'label' => 'Project title(s) + short description', 'type' => 'textarea', 'show' => [['projects', 'gt', 0]]],
            ['id' => 'project_link', 'label' => 'Project upload location / link(s) (Drive folder or Classroom link)', 'type' => 'text', 'show' => [['projects', 'gt', 0]]],
        ]],

        ['id' => 'laptops', 'title' => 'Laptops and tech issues', 'icon' => 'device-laptop', 'fields' => [
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

        ['id' => 'install', 'title' => 'Installation and tools', 'icon' => 'tools', 'fields' => [
            ['id' => 'install', 'label' => 'Did you carry out any installation or maintenance today?', 'type' => 'radio', 'req' => true, 'opts' => YES_NO],
            ['id' => 'install_type', 'label' => 'Type of work carried out (tick all that apply)', 'type' => 'checks', 'show' => $install, 'opts' => [
                'Projector installation', 'Interactive Board installation', 'Interactive Board calibration / setup',
                'Inverter configuration / troubleshooting', 'General maintenance / inspection', 'Other (specify in remarks)']],
            ['id' => 'install_location', 'label' => 'Installation location / school (if applicable)', 'type' => 'text', 'show' => $install],
            ['id' => 'install_items', 'label' => 'Number of items installed / worked on (number)', 'type' => 'number', 'show' => $install],
            ['id' => 'install_desc', 'label' => 'Brief description of work done', 'type' => 'textarea', 'req' => true, 'show' => $install],
            ['id' => 'install_done', 'label' => 'Was the work completed successfully?', 'type' => 'radio', 'show' => $install, 'opts' => [
                'Yes', 'Partially', 'No (explain in remarks)']],
            ['id' => 'install_evidence', 'label' => 'Evidence link (photos/videos/Drive folder)', 'type' => 'text', 'show' => $install, 'h' => 'Evidence link (photos/videos/Drive folder)'],
            ['id' => 'tools_used', 'label' => 'Did you use any office / installation tools today?', 'type' => 'radio', 'req' => true, 'opts' => YES_NO],
            ['id' => 'tools_list', 'label' => 'List of tools used (tick all that apply)', 'type' => 'checks', 'show' => $tools, 'opts' => [
                'Drill machine', 'Drill bits', 'Screwdriver set', 'Hammer', 'Allen keys', 'Trunking tools', 'Multimeter',
                'Cable ties', 'Laptop (for setup/configuration)', 'Other (specify below)']],
            ['id' => 'tools_other', 'label' => 'Other tools used (if any)', 'type' => 'text', 'show' => $tools],
            ['id' => 'tools_returned', 'label' => 'Did you pack and return all tools after use?', 'type' => 'radio', 'req' => true, 'show' => $tools, 'opts' => [
                'Yes – all tools accounted for', 'Partially – some tools still pending', 'No – tools missing (explain)']],
            ['id' => 'tools_missing', 'label' => 'If any tool is missing or damaged, explain clearly', 'type' => 'textarea',
                'show' => [['tools_used', 'eq', 'Yes'], ['tools_returned', 'ne', 'Yes – all tools accounted for'], ['tools_returned', 'nempty', '']]],
            ['id' => 'tools_confirm', 'label' => 'Tool handling confirmation (Required)', 'type' => 'confirm', 'req' => true,
                'text' => 'I confirm all tools listed were properly packed and returned, or I stated any exceptions.'],
        ]],

        ['id' => 'media', 'title' => 'Media', 'icon' => 'movie', 'fields' => [
            ['id' => 'video', 'label' => 'Did you edit any video today?', 'type' => 'radio', 'req' => true, 'opts' => YES_NO],
            ['id' => 'video_tool', 'label' => 'Video editing tool used', 'type' => 'radio', 'show' => $video, 'opts' => [
                'Google Vids (Preferred)', 'Clipchamp', 'Other (must have no watermark)']],
            ['id' => 'video_count', 'label' => 'Number of videos edited today (enter a number)', 'type' => 'number', 'show' => $video],
            ['id' => 'video_link', 'label' => 'Link to finished video folder (Drive link required)', 'type' => 'text', 'req' => true, 'show' => $video],
            ['id' => 'photopea', 'label' => 'Did you edit any images today using Photopea?', 'type' => 'radio', 'req' => true, 'opts' => YES_NO],
            ['id' => 'photopea_count', 'label' => 'Number of images edited (enter a number)', 'type' => 'number', 'show' => $pp],
            ['id' => 'photopea_link', 'label' => 'Link to edited images folder (Drive link)', 'type' => 'text', 'show' => $pp],
        ]],

        ['id' => 'wrap', 'title' => 'Wrap-up', 'icon' => 'checklist', 'fields' => [
            ['id' => 'pending_tasks', 'label' => 'List pending tasks to do (clear bullet points)', 'type' => 'textarea'],
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

/** Flat list of fields with their options resolved. */
function report_fields(): array
{
    static $flat = null;
    if ($flat === null) {
        $flat = [];
        foreach (report_schema_resolved() as $sec) {
            foreach ($sec['fields'] as $f) {
                $f['section'] = $sec['id'];
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
                $f['opts'] = array_values(setting('locations') ?: []);
            }
        }
    }
    return $schema;
}

function field_visible(array $f, array $data): bool
{
    foreach ($f['show'] ?? [] as [$fid, $op, $val]) {
        $v = $data[$fid] ?? '';
        if (is_array($v)) {
            $v = implode(', ', $v);
        }
        $v = (string)$v;
        $ok = match ($op) {
            'eq'     => $v === (string)$val,
            'ne'     => $v !== (string)$val,
            'gt'     => is_numeric($v) && (float)$v > (float)$val,
            'nempty' => $v !== '',
            default  => true,
        };
        if (!$ok) {
            return false;
        }
    }
    return true;
}

/** Validate and clean submitted report answers. Returns [cleanData, errors]. */
function clean_report(array $in): array
{
    $out = [];
    $errors = [];
    foreach (report_fields() as $id => $f) {
        $raw = $in[$id] ?? null;
        switch ($f['type']) {
            case 'checks':
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
            $out[$id] = $f['type'] === 'checks' ? [] : '';
            unset($errors[$id]);
            continue;
        }
        $empty = $out[$id] === '' || $out[$id] === [];
        if (!empty($f['req']) && $empty && !isset($errors[$id])) {
            $errors[$id] = 'This is required.';
        }
    }
    return [$out, $errors];
}

function norm_header(string $s): string
{
    $s = str_replace(['’', '‘', '–', '—'], ["'", "'", '-', '-'], $s);
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}
