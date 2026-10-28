<?php
declare(strict_types=1);

namespace ClinicFlow\Services;

use PDO;
use ClinicFlow\Utils\Uuid;

/**
 * Consent form templates a clinic designs for itself — one per purpose, each
 * with its own wording and questions.
 *
 * Editing a template bumps its version. Records keep a copy of the wording
 * they were signed against, so a later edit never rewrites history.
 */
final class ConsentForms
{
    /** type => [label, help, takes options, is static text] */
    public const FIELD_TYPES = [
        'heading'     => ['Section heading', 'A heading to break the form into parts', false, true],
        'paragraph'   => ['Paragraph', 'Text for the patient to read', false, true],
        'acknowledge' => ['Must be ticked', 'A statement the patient has to tick before signing', false, false],
        'checkbox'    => ['Optional tick', 'A statement the patient may tick', false, false],
        'radio'       => ['Choose one', 'A set of choices, one of which is picked', true, false],
        'select'      => ['Dropdown', 'A list to choose from', true, false],
        'text'        => ['Short answer', 'A single line of text', false, false],
        'textarea'    => ['Long answer', 'A few lines of text', false, false],
        'date'        => ['Date', 'A date the patient fills in', false, false],
        'initials'    => ['Initials', 'Initials beside a particular clause', false, false],
    ];

    public const MAX_FIELDS = 60;
    public const MAX_FORMS = 40;

    private PDO $db;
    private string $tenantId;

    public function __construct(PDO $db, ?string $tenantId = null)
    {
        $this->db = $db;
        $this->tenantId = $tenantId ?? \ClinicFlow\Shared\TenantContext::getTenantId();
    }

    /** Whether this database has the consent tables yet. */
    public static function isAvailable(PDO $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $db->query("SELECT 1 FROM consent_forms LIMIT 1");
            $db->query("SELECT 1 FROM consent_records LIMIT 1");
            return $cache = true;
        } catch (\Throwable $e) {
            return $cache = false;
        }
    }

    /** @return array<int, array> every form this clinic has, newest order first */
    public function all(bool $activeOnly = false): array
    {
        if (!self::isAvailable($this->db)) {
            return [];
        }
        $sql = "SELECT * FROM consent_forms WHERE tenant_id = :tid"
            . ($activeOnly ? " AND is_active = 1" : '')
            . " ORDER BY sort_order ASC, name ASC";
        try {
            $st = $this->db->prepare($sql);
            $st->execute(['tid' => $this->tenantId]);
            return array_map([$this, 'shape'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (\Throwable $e) {
            error_log('[ConsentForms] all: ' . $e->getMessage());
            return [];
        }
    }

    public function find(string $id): ?array
    {
        if (!self::isAvailable($this->db) || $id === '') {
            return null;
        }
        try {
            $st = $this->db->prepare("SELECT * FROM consent_forms WHERE id = ? AND tenant_id = ? LIMIT 1");
            $st->execute([$id, $this->tenantId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ? $this->shape($row) : null;
        } catch (\Throwable $e) {
            error_log('[ConsentForms] find: ' . $e->getMessage());
            return null;
        }
    }

    private function shape(array $row): array
    {
        $fields = json_decode((string) ($row['fields'] ?? '[]'), true);
        $row['fields'] = is_array($fields) ? $fields : [];
        $row['require_witness'] = !empty($row['require_witness']);
        $row['is_active'] = !empty($row['is_active']);
        $row['version'] = (int) ($row['version'] ?? 1);
        return $row;
    }

    /** A blank form to start from. */
    public static function blank(): array
    {
        return [
            'id' => '',
            'name' => '',
            'purpose' => '',
            'description' => '',
            'intro' => '',
            'declaration' => 'I confirm that I have read and understood the above, that my questions have been answered, and that I give my consent freely.',
            'fields' => [],
            'require_witness' => false,
            'is_active' => true,
            'version' => 1,
        ];
    }

    /**
     * Validate a submitted template. Unknown field types are dropped rather
     * than stored, so a form can never render something unexpected later.
     */
    public static function sanitise(array $in): array
    {
        $out = self::blank();
        $str = static fn(string $k, int $max, string $default = ''): string
            => mb_substr(trim((string) ($in[$k] ?? $default)), 0, $max);

        $out['name'] = $str('name', 160);
        $out['purpose'] = $str('purpose', 80);
        $out['description'] = $str('description', 400);
        $out['intro'] = $str('intro', 8000);
        $out['declaration'] = $str('declaration', 2000);
        $out['require_witness'] = !empty($in['require_witness']);
        $out['is_active'] = !empty($in['is_active']);

        $fields = [];
        foreach ((array) ($in['fields'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $type = (string) ($raw['type'] ?? '');
            if (!isset(self::FIELD_TYPES[$type])) {
                continue;
            }
            $label = mb_substr(trim((string) ($raw['label'] ?? '')), 0, 600);
            if ($label === '') {
                continue; // a field with nothing written on it is not a field
            }
            $field = [
                'key' => self::fieldKey((string) ($raw['key'] ?? ''), $fields),
                'type' => $type,
                'label' => $label,
                'help' => mb_substr(trim((string) ($raw['help'] ?? '')), 0, 300),
                'required' => !empty($raw['required']),
            ];
            if (self::FIELD_TYPES[$type][2]) {
                $options = [];
                // The designer sends one choice per line; a template defines
                // them as an array. Casting an array to a string turns the
                // whole list into the single choice "Array".
                $rawOptions = $raw['options'] ?? '';
                $lines = is_array($rawOptions)
                    ? $rawOptions
                    : (preg_split('/\r\n|\r|\n/', (string) $rawOptions) ?: []);
                foreach ($lines as $line) {
                    $line = mb_substr(trim((string) $line), 0, 160);
                    if ($line !== '' && !in_array($line, $options, true)) {
                        $options[] = $line;
                    }
                }
                $field['options'] = array_slice($options, 0, 30);
                if ($field['options'] === []) {
                    continue; // a choice with no choices cannot be answered
                }
            }
            if (self::FIELD_TYPES[$type][3]) {
                $field['required'] = false; // static text is never answered
            }
            if ($type === 'acknowledge') {
                $field['required'] = true;   // that is what it is for
            }
            $fields[] = $field;
            if (count($fields) >= self::MAX_FIELDS) {
                break;
            }
        }
        $out['fields'] = $fields;
        return $out;
    }

    /** A stable, unique key so answers survive a label being reworded. */
    private static function fieldKey(string $wanted, array $existing): string
    {
        $key = preg_replace('/[^a-z0-9_]+/', '_', strtolower($wanted)) ?: '';
        $key = trim((string) $key, '_');
        if ($key === '') {
            $key = 'f' . substr(bin2hex(random_bytes(4)), 0, 6);
        }
        $taken = array_column($existing, 'key');
        $base = mb_substr($key, 0, 40);
        $key = $base;
        $n = 2;
        while (in_array($key, $taken, true)) {
            $key = $base . '_' . $n++;
        }
        return $key;
    }

    /** Create or update; editing an existing form moves its version on. */
    public function save(array $form, ?string $id = null): string
    {
        if (!self::isAvailable($this->db)) {
            throw new \RuntimeException('This database has not been set up for consent forms yet. A developer can apply the pending database changes from Developer → Database.');
        }
        if (trim((string) $form['name']) === '') {
            throw new \InvalidArgumentException('Give the form a name.');
        }
        if ($form['fields'] === [] && trim((string) $form['intro']) === '') {
            throw new \InvalidArgumentException('A form needs some wording or at least one question.');
        }

        $existing = $id !== null && $id !== '' ? $this->find($id) : null;
        $json = json_encode($form['fields'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($existing) {
            $this->db->prepare("
                UPDATE consent_forms
                   SET name = :name, purpose = :purpose, description = :description, intro = :intro,
                       declaration = :declaration, fields = :fields, require_witness = :witness,
                       is_active = :active, version = version + 1, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND tenant_id = :tid
            ")->execute([
                'name' => $form['name'], 'purpose' => $form['purpose'], 'description' => $form['description'],
                'intro' => $form['intro'], 'declaration' => $form['declaration'], 'fields' => $json,
                'witness' => $form['require_witness'] ? 1 : 0, 'active' => $form['is_active'] ? 1 : 0,
                'id' => $existing['id'], 'tid' => $this->tenantId,
            ]);
            return (string) $existing['id'];
        }

        if (count($this->all()) >= self::MAX_FORMS) {
            throw new \InvalidArgumentException('This clinic already has ' . self::MAX_FORMS . ' forms. Retire one before adding another.');
        }

        $newId = 'cf-' . Uuid::uuidv7();
        $this->db->prepare("
            INSERT INTO consent_forms (id, tenant_id, name, purpose, description, intro, declaration, fields, require_witness, is_active, version, sort_order, created_by)
            VALUES (:id, :tid, :name, :purpose, :description, :intro, :declaration, :fields, :witness, :active, 1, :sort, :by)
        ")->execute([
            'id' => $newId, 'tid' => $this->tenantId, 'name' => $form['name'], 'purpose' => $form['purpose'],
            'description' => $form['description'], 'intro' => $form['intro'], 'declaration' => $form['declaration'],
            'fields' => $json, 'witness' => $form['require_witness'] ? 1 : 0, 'active' => $form['is_active'] ? 1 : 0,
            'sort' => count($this->all()), 'by' => $_SESSION['user_name'] ?? null,
        ]);
        return $newId;
    }

    /** Copy a form, so a variant can be made without disturbing the original. */
    public function duplicate(string $id): ?string
    {
        $form = $this->find($id);
        if (!$form) {
            return null;
        }
        $copy = self::sanitise(array_merge($form, [
            'name' => mb_substr($form['name'] . ' (copy)', 0, 160),
            'fields' => $form['fields'],
            'is_active' => false,
        ]));
        return $this->save($copy);
    }

    /**
     * Retire a form. It is never deleted outright while records point at it,
     * because a signed consent has to keep saying which form it came from.
     */
    public function retire(string $id): void
    {
        $this->db->prepare("UPDATE consent_forms SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ?")
            ->execute([$id, $this->tenantId]);
    }

    public function restore(string $id): void
    {
        $this->db->prepare("UPDATE consent_forms SET is_active = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_id = ?")
            ->execute([$id, $this->tenantId]);
    }

    /** Delete, but only when nothing has been signed against it. */
    public function delete(string $id): bool
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM consent_records WHERE form_id = ? AND tenant_id = ?");
        $st->execute([$id, $this->tenantId]);
        if ((int) $st->fetchColumn() > 0) {
            return false;
        }
        $this->db->prepare("DELETE FROM consent_forms WHERE id = ? AND tenant_id = ?")->execute([$id, $this->tenantId]);
        return true;
    }

    /** How many consents have been signed against each form. */
    public function useCounts(): array
    {
        if (!self::isAvailable($this->db)) {
            return [];
        }
        try {
            $st = $this->db->prepare("SELECT form_id, COUNT(*) AS n FROM consent_records WHERE tenant_id = ? GROUP BY form_id");
            $st->execute([$this->tenantId]);
            return $st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Ready-made templates a clinic can start from, rather than facing an
     * empty form designer. key => [label, description, form]
     */
    public static function samples(): array
    {
        return [
            'treatment' => [
                'General treatment and examination',
                'The everyday consent for being examined and treated at the clinic.',
                [
                    'name' => 'Consent to treatment and examination',
                    'purpose' => 'General treatment',
                    'description' => 'Signed at registration or before the first examination of a visit.',
                    'intro' => "I am attending this clinic to be examined and treated.\n\n"
                        . "I understand that the examination may include taking my medical history, a physical examination, "
                        . "measuring my vital signs, and where necessary laboratory tests, imaging or procedures that the "
                        . "treating clinician considers appropriate.\n\n"
                        . "I understand that no promise has been made to me about the result of any treatment, that I may ask "
                        . "questions at any time, and that I may withdraw my consent before a procedure begins.",
                    'declaration' => 'I confirm that I have read and understood this form, that my questions have been answered, and that I give my consent freely.',
                    'require_witness' => false,
                    'is_active' => true,
                    'fields' => [
                        ['key' => 'understood', 'type' => 'acknowledge', 'label' => 'I have read and understood what is written above.', 'help' => '', 'required' => true],
                        ['key' => 'questions', 'type' => 'acknowledge', 'label' => 'I have had the chance to ask questions and they have been answered.', 'help' => '', 'required' => true],
                        ['key' => 'interpreter', 'type' => 'radio', 'label' => 'Did you need an interpreter?', 'help' => '', 'required' => true, 'options' => ['No', 'Yes, and one was provided', 'Yes, but one was not available']],
                        ['key' => 'students_heading', 'type' => 'heading', 'label' => 'Teaching and records', 'help' => '', 'required' => false],
                        ['key' => 'students', 'type' => 'checkbox', 'label' => 'I agree to a student or trainee being present during my consultation.', 'help' => 'You may decline without it affecting your care.', 'required' => false],
                        ['key' => 'photos', 'type' => 'checkbox', 'label' => 'I agree to clinical photographs being taken where they help my treatment.', 'help' => '', 'required' => false],
                        ['key' => 'contact', 'type' => 'radio', 'label' => 'How may we contact you about results?', 'help' => '', 'required' => true, 'options' => ['Phone call', 'SMS', 'Email', 'Do not contact me — I will call the clinic']],
                        ['key' => 'notes', 'type' => 'textarea', 'label' => 'Anything you would like to add', 'help' => 'Optional', 'required' => false],
                    ],
                ],
            ],
            'procedure' => [
                'Procedure or minor surgery',
                'For a named procedure, with its risks and the alternatives.',
                [
                    'name' => 'Consent to a procedure',
                    'purpose' => 'Procedure',
                    'description' => 'Completed with the clinician before a procedure or minor surgery.',
                    'intro' => "The procedure proposed for me has been explained, including what it involves, why it is "
                        . "recommended, and what is likely to happen if it is not done.\n\n"
                        . "I understand that no procedure is without risk, and that unforeseen circumstances may make "
                        . "further treatment necessary.",
                    'declaration' => 'I consent to the procedure named above being carried out by the clinician named, or by a suitably qualified colleague.',
                    'require_witness' => true,
                    'is_active' => true,
                    'fields' => [
                        ['key' => 'procedure_name', 'type' => 'text', 'label' => 'Procedure', 'help' => 'Written in plain words, not only in clinical terms', 'required' => true],
                        ['key' => 'clinician', 'type' => 'text', 'label' => 'Clinician carrying it out', 'help' => '', 'required' => true],
                        ['key' => 'risks', 'type' => 'textarea', 'label' => 'Risks and side effects explained', 'help' => 'The ones discussed with this patient', 'required' => true],
                        ['key' => 'alternatives', 'type' => 'textarea', 'label' => 'Alternatives discussed', 'help' => 'Including doing nothing', 'required' => false],
                        ['key' => 'anaesthetic', 'type' => 'radio', 'label' => 'Anaesthetic', 'help' => '', 'required' => true, 'options' => ['None', 'Local', 'Sedation', 'General']],
                        ['key' => 'explained', 'type' => 'acknowledge', 'label' => 'The procedure, its risks and the alternatives have been explained to me.', 'help' => '', 'required' => true],
                        ['key' => 'initials_risks', 'type' => 'initials', 'label' => 'Initials beside the risks explained above', 'help' => '', 'required' => true],
                    ],
                ],
            ],
            'privacy' => [
                'Privacy and sharing of records',
                'How the clinic may hold and share a patient\'s information.',
                [
                    'name' => 'Privacy and sharing of medical records',
                    'purpose' => 'Privacy',
                    'description' => 'How this clinic holds your records and who it may share them with.',
                    'intro' => "This clinic keeps a medical record for every patient. It is held securely and seen only by "
                        . "staff involved in your care, except where you agree otherwise or where the law requires it.\n\n"
                        . "You may ask to see your record, ask for a copy, or ask for a correction at any time.",
                    'declaration' => 'I have read how my information is held and shared, and I agree to it.',
                    'require_witness' => false,
                    'is_active' => true,
                    'fields' => [
                        ['key' => 'read_privacy', 'type' => 'acknowledge', 'label' => 'I have read how my information is held and used.', 'help' => '', 'required' => true],
                        ['key' => 'share_gp', 'type' => 'checkbox', 'label' => 'You may share relevant details with my usual doctor or clinic.', 'help' => '', 'required' => false],
                        ['key' => 'share_family', 'type' => 'text', 'label' => 'Family member who may be given information on my behalf', 'help' => 'Leave blank if nobody', 'required' => false],
                        ['key' => 'reminders', 'type' => 'checkbox', 'label' => 'You may send me appointment reminders and health information.', 'help' => '', 'required' => false],
                    ],
                ],
            ],
        ];
    }

    /** Install one of the ready-made templates for this clinic. */
    public function installSample(string $key): string
    {
        $samples = self::samples();
        if (!isset($samples[$key])) {
            throw new \InvalidArgumentException('That template does not exist.');
        }
        return $this->save(self::sanitise($samples[$key][2]));
    }
}
