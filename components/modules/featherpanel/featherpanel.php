<?php
/**
 * FeatherPanel module for Blesta.
 *
 * Provisions game servers, VDS (virtual machines) and WebSpaces (web hosting) on a FeatherPanel
 * installation through its admin API: create, suspend, unsuspend, delete, change package and edit,
 * plus a client tab with the server status and a one-click panel login.
 *
 * @link https://featherpanel.com
 */
class Featherpanel extends Module
{
    /** Service field keys stored by this module. */
    const FIELD_TYPE = 'featherpanel_type';
    const FIELD_EXTERNAL_ID = 'featherpanel_external_id';
    const FIELD_ID = 'featherpanel_id';
    const FIELD_UUID = 'featherpanel_uuid';
    const FIELD_CREATION_ID = 'featherpanel_creation_id';
    const FIELD_USER_UUID = 'featherpanel_user_uuid';
    const FIELD_LABEL = 'featherpanel_label';

    /** Client meta keys (per module) for the linked panel account. */
    const META_USER_UUID = 'featherpanel_user_uuid';
    const META_USERNAME = 'featherpanel_username';
    const META_PASSWORD = 'featherpanel_password';

    /** @var FeatherpanelProvisioner|null Set by tests to bypass building one from the module row. */
    public $provisionerOverride = null;

    public function __construct()
    {
        Loader::loadComponents($this, ['Input']);
        Language::loadLang('featherpanel', null, dirname(__FILE__) . DS . 'language' . DS);
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        foreach (['featherpanel_api', 'featherpanel_payloads', 'featherpanel_provisioner'] as $lib) {
            Loader::load(dirname(__FILE__) . DS . 'lib' . DS . $lib . '.php');
        }
    }

    // ------------------------------------------------------------------ module rows

    public function getGroupOrderOptions()
    {
        return [
            'first' => Language::_('Featherpanel.order_options.first', true),
            'least' => Language::_('Featherpanel.order_options.least', true),
        ];
    }

    public function selectModuleRow($module_group_id)
    {
        if (!isset($this->ModuleManager)) {
            Loader::loadModels($this, ['ModuleManager']);
        }
        $group = $this->ModuleManager->getGroup($module_group_id);
        if (!$group || empty($group->rows)) {
            return 0;
        }
        if ($group->add_order === 'least') {
            $best = null;
            $bestCount = PHP_INT_MAX;
            foreach ($group->rows as $row) {
                $count = $this->countServicesOnRow($row->id);
                if ($count < $bestCount) {
                    $best = $row->id;
                    $bestCount = $count;
                }
            }

            return (int) $best;
        }

        return (int) $group->rows[0]->id;
    }

    private function countServicesOnRow($rowId)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        return (int) $this->Record->select()->from('services')
            ->where('module_row_id', '=', $rowId)
            ->where('status', 'in', ['active', 'suspended', 'pending'])
            ->numResults();
    }

    public function manageModule($module, array &$vars)
    {
        $this->view = $this->makeView('manage');
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
        $this->view->set('module', $module);

        return $this->view->fetch();
    }

    public function manageAddRow(array &$vars)
    {
        $this->view = $this->makeView('add_row');
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
        if (!empty($vars) && empty($vars['verify_ssl'])) {
            $vars['verify_ssl'] = 'false';
        }
        $this->view->set('vars', (object) $vars);

        return $this->view->fetch();
    }

    public function manageEditRow($module_row, array &$vars)
    {
        $this->view = $this->makeView('edit_row');
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
        if (empty($vars)) {
            $vars = (array) $module_row->meta;
        } elseif (empty($vars['verify_ssl'])) {
            $vars['verify_ssl'] = 'false';
        }
        $this->view->set('vars', (object) $vars);

        return $this->view->fetch();
    }

    public function addModuleRow(array &$vars)
    {
        $fields = ['server_name', 'panel_url', 'api_key', 'verify_ssl', 'external_id_prefix', 'vds_wait_seconds'];
        if (empty($vars['verify_ssl'])) {
            $vars['verify_ssl'] = 'false';
        }
        $vars['panel_url'] = rtrim(trim($vars['panel_url'] ?? ''), '/');
        $vars['external_id_prefix'] = trim($vars['external_id_prefix'] ?? '') !== '' ? trim($vars['external_id_prefix']) : 'blesta-';
        $vars['vds_wait_seconds'] = isset($vars['vds_wait_seconds']) && $vars['vds_wait_seconds'] !== ''
            ? (string) (int) $vars['vds_wait_seconds'] : '60';

        $this->lastConnectionError = null;
        $this->Input->setRules($this->getRowRules($vars));
        if (!$this->Input->validates($vars)) {
            if ($this->lastConnectionError !== null) {
                $errors = $this->Input->errors();
                $errors['api_key']['valid'] = Language::_('Featherpanel.!error.api_key.valid', true) . ' ' . $this->lastConnectionError;
                $this->Input->setErrors($errors);
            }

            return;
        }

        $meta = [];
        foreach ($fields as $key) {
            $meta[] = ['key' => $key, 'value' => $vars[$key] ?? '', 'encrypted' => $key === 'api_key' ? 1 : 0];
        }

        return $meta;
    }

    public function editModuleRow($module_row, array &$vars)
    {
        return $this->addModuleRow($vars);
    }

    private function getRowRules(array $vars)
    {
        return [
            'server_name' => [
                'empty' => ['rule' => 'isEmpty', 'negate' => true, 'message' => Language::_('Featherpanel.!error.server_name.empty', true)],
            ],
            'panel_url' => [
                'format' => [
                    'rule' => function ($url) {
                        return (bool) preg_match('#^https?://[^\s/]+#i', (string) $url);
                    },
                    'message' => Language::_('Featherpanel.!error.panel_url.format', true),
                ],
            ],
            'external_id_prefix' => [
                'format' => [
                    'rule' => ['matches', '/^[A-Za-z0-9._:-]{1,40}$/'],
                    'message' => Language::_('Featherpanel.!error.external_id_prefix.format', true),
                ],
            ],
            'vds_wait_seconds' => [
                'range' => [
                    'rule' => function ($value) {
                        return is_numeric($value) && (int) $value >= 0 && (int) $value <= 600;
                    },
                    'message' => Language::_('Featherpanel.!error.vds_wait_seconds.range', true),
                ],
            ],
            'api_key' => [
                'empty' => ['rule' => 'isEmpty', 'negate' => true, 'message' => Language::_('Featherpanel.!error.api_key.empty', true)],
                'valid' => [
                    'rule' => function ($key) use ($vars) {
                        $error = $this->buildProvisioner((object) $vars)->testConnection();
                        if ($error !== null) {
                            $this->lastConnectionError = $error;
                        }

                        return $error === null;
                    },
                    'message' => Language::_('Featherpanel.!error.api_key.valid', true),
                    'final' => true,
                ],
            ],
        ];
    }

    /** @var string|null */
    private $lastConnectionError = null;

    // ------------------------------------------------------------------ packages

    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);
        $vars = $vars ?: new stdClass();
        $meta = isset($vars->meta) ? (array) $vars->meta : [];
        $type = $meta['product_type'] ?? 'game';
        $lists = $this->getPackageLists($vars, $type, $meta);

        $fields = new ModuleFields();
        $fields->setHtml("
            <script type=\"text/javascript\">
                $(document).ready(function() {
                    $('#featherpanel_product_type, #featherpanel_realm_id, #featherpanel_location_id, #featherpanel_vm_node_id').change(function() {
                        fetchModuleOptions();
                    });
                });
            </script>
        ");

        $this->addSelect($fields, 'product_type', [
            'game' => Language::_('Featherpanel.product_type.game', true),
            'vds' => Language::_('Featherpanel.product_type.vds', true),
            'webspace' => Language::_('Featherpanel.product_type.webspace', true),
        ], $type);

        if ($lists['error'] !== null) {
            $fields->setHtml('<div class="alert alert-warning">' . $this->Html->safe($lists['error']) . '</div>');
        }

        $defaults = FeatherpanelPayloads::resolveConfig($type, []);
        switch ($type) {
            case 'game':
                $this->addSelect($fields, 'location_id', ['' => Language::_('Featherpanel.package.any', true)] + $lists['locations'], $meta['location_id'] ?? '');
                $this->addSelect($fields, 'node_id', ['' => Language::_('Featherpanel.package.auto_node', true)] + $lists['nodes'], $meta['node_id'] ?? '');
                $this->addSelect($fields, 'realm_id', ['' => ''] + $lists['realms'], $meta['realm_id'] ?? '');
                $this->addSelect($fields, 'spell_id', ['' => ''] + $lists['spells'], $meta['spell_id'] ?? '');
                foreach (['memory', 'swap', 'disk', 'cpu', 'io', 'threads', 'database_limit', 'backup_limit', 'allocation_limit', 'image', 'startup'] as $key) {
                    $this->addText($fields, $key, $meta[$key] ?? $defaults[$key]);
                }
                $this->addTextarea($fields, 'environment', $meta['environment'] ?? '');
                $this->addCheckbox($fields, 'oom_killer', $meta['oom_killer'] ?? '1');
                $this->addCheckbox($fields, 'skip_scripts', $meta['skip_scripts'] ?? '0');
                break;
            case 'vds':
                $this->addSelect($fields, 'vm_node_id', ['' => ''] + $lists['vm_nodes'], $meta['vm_node_id'] ?? '');
                $this->addSelect($fields, 'template_id', ['' => ''] + $lists['templates'], $meta['template_id'] ?? '');
                foreach (['memory', 'cpus', 'cores', 'disk', 'storage', 'bridge', 'pve_node', 'backup_limit', 'bandwidth_limit_gb', 'ci_user'] as $key) {
                    $this->addText($fields, $key, $meta[$key] ?? ($defaults[$key] ?? ''));
                }
                $this->addCheckbox($fields, 'on_boot', $meta['on_boot'] ?? '1');
                break;
            case 'webspace':
                $this->addSelect($fields, 'web_node_id', ['' => ''] + $lists['web_nodes'], $meta['web_node_id'] ?? '');
                $this->addSelect($fields, 'webplate_id', ['' => ''] + $lists['webplates'], $meta['webplate_id'] ?? '');
                $this->addSelect($fields, 'hosting_package_id', ['' => Language::_('Featherpanel.package.none', true)] + $lists['hosting_packages'], $meta['hosting_package_id'] ?? '');
                foreach (['disk', 'cpu_limit', 'memory_limit', 'bandwidth_limit_gb', 'database_limit', 'mailbox_limit'] as $key) {
                    $this->addText($fields, $key, $meta[$key] ?? ($defaults[$key] ?? ''));
                }
                $this->addCheckbox($fields, 'ssl', $meta['ssl'] ?? '1');
                break;
        }

        return $fields;
    }

    /**
     * Loads select options from the panel. Fails soft: an unreachable panel still lets the
     * admin type the ids by hand (the select falls back to the saved value).
     */
    public function getPackageLists($vars, $type = 'game', array $meta = [])
    {
        $lists = [
            'error' => null, 'locations' => [], 'nodes' => [], 'realms' => [], 'spells' => [],
            'vm_nodes' => [], 'templates' => [], 'web_nodes' => [], 'webplates' => [], 'hosting_packages' => [],
        ];
        $row = $this->rowForPackageVars($vars);
        if (!$row) {
            $lists['error'] = Language::_('Featherpanel.!error.no_row', true);

            return $this->keepSavedValues($lists, $meta);
        }
        $api = $this->buildApi($row->meta);
        $collect = function ($path, $key, $query = []) use ($api, &$lists) {
            $response = $api->get($path, $query + ['limit' => 100]);
            if (!$response->ok()) {
                $lists['error'] = Language::_('Featherpanel.!error.lists', true, $response->errorMessage());

                return [];
            }

            return $response->data()[$key] ?? [];
        };
        $label = function ($item) {
            return '#' . $item['id'] . ' ' . ($item['name'] ?? '');
        };

        switch ($type) {
            case 'game':
                foreach ($collect('/api/admin/locations', 'locations') as $l) {
                    if (($l['type'] ?? 'game') === 'game') {
                        $lists['locations'][$l['id']] = $label($l);
                    }
                }
                $query = !empty($meta['location_id']) ? ['location_id' => (int) $meta['location_id']] : [];
                foreach ($collect('/api/admin/nodes', 'nodes', $query) as $n) {
                    $lists['nodes'][$n['id']] = $label($n) . ' (' . ($n['fqdn'] ?? '') . ')';
                }
                foreach ($collect('/api/admin/realms', 'realms') as $r) {
                    $lists['realms'][$r['id']] = $label($r);
                }
                $query = !empty($meta['realm_id']) ? ['realm_id' => (int) $meta['realm_id']] : [];
                foreach ($collect('/api/admin/spells', 'spells', $query) as $s) {
                    if (empty($meta['realm_id']) || (int) $s['realm_id'] === (int) $meta['realm_id']) {
                        $lists['spells'][$s['id']] = $label($s);
                    }
                }
                break;
            case 'vds':
                foreach ($collect('/api/admin/vm-nodes', 'vm_nodes') as $n) {
                    $lists['vm_nodes'][$n['id']] = $label($n) . ' (' . ($n['fqdn'] ?? '') . ')';
                }
                $nodeId = !empty($meta['vm_node_id']) ? (int) $meta['vm_node_id'] : (int) key($lists['vm_nodes']);
                if ($nodeId > 0) {
                    foreach ($collect('/api/admin/vm-nodes/' . $nodeId . '/templates', 'templates') as $t) {
                        $lists['templates'][$t['id']] = $label($t) . ' [' . ($t['guest_type'] ?? 'qemu') . ']';
                    }
                }
                break;
            case 'webspace':
                foreach ($collect('/api/admin/web-nodes', 'web_nodes') as $n) {
                    $lists['web_nodes'][$n['id']] = $label($n) . ' (' . ($n['fqdn'] ?? '') . ')';
                }
                foreach ($collect('/api/admin/webplates', 'webplates') as $w) {
                    $lists['webplates'][$w['id']] = $label($w) . ' [' . ($w['runtime'] ?? '') . ']';
                }
                foreach ($collect('/api/admin/hosting-packages', 'packages') as $p) {
                    $lists['hosting_packages'][$p['id']] = $label($p);
                }
                break;
        }

        return $this->keepSavedValues($lists, $meta);
    }

    private function keepSavedValues(array $lists, array $meta)
    {
        $map = [
            'location_id' => 'locations', 'node_id' => 'nodes', 'realm_id' => 'realms', 'spell_id' => 'spells',
            'vm_node_id' => 'vm_nodes', 'template_id' => 'templates', 'web_node_id' => 'web_nodes',
            'webplate_id' => 'webplates', 'hosting_package_id' => 'hosting_packages',
        ];
        foreach ($map as $key => $list) {
            if (!empty($meta[$key]) && !isset($lists[$list][$meta[$key]])) {
                $lists[$list][$meta[$key]] = '#' . $meta[$key];
            }
        }

        return $lists;
    }

    private function rowForPackageVars($vars)
    {
        $vars = (object) $vars;
        if (!empty($vars->module_group)) {
            $rowId = $this->selectModuleRow($vars->module_group);
        } else {
            $rowId = $vars->module_row ?? 0;
        }
        $row = $rowId ? $this->getModuleRow($rowId) : null;
        if (!$row) {
            $rows = $this->getModuleRows();
            $row = !empty($rows) ? $rows[0] : null;
        }

        return $row ?: null;
    }

    public function addPackage(?array $vars = null)
    {
        $meta = isset($vars['meta']) ? (array) $vars['meta'] : [];
        $type = $meta['product_type'] ?? '';
        $this->Input->setRules($this->getPackageRules($type));
        if (!$this->Input->validates($meta)) {
            return;
        }

        $out = [['key' => 'product_type', 'value' => $type, 'encrypted' => 0]];
        $checkboxes = ['oom_killer', 'skip_scripts', 'on_boot', 'ssl'];
        foreach (array_keys(FeatherpanelPayloads::packageKeys($type)) as $key) {
            $value = $meta[$key] ?? '';
            if (in_array($key, $checkboxes, true)) {
                $value = in_array((string) $value, ['1', 'true', 'on'], true) ? '1' : '0';
            }
            $out[] = ['key' => $key, 'value' => is_string($value) ? trim($value) : $value, 'encrypted' => 0];
        }

        return $out;
    }

    public function editPackage($package, ?array $vars = null)
    {
        return $this->addPackage($vars);
    }

    private function getPackageRules($type)
    {
        $required = function ($field) {
            return [
                'empty' => [
                    'rule' => function ($value) {
                        return is_numeric($value) && (int) $value > 0;
                    },
                    'message' => Language::_('Featherpanel.!error.package.required', true, Language::_('Featherpanel.package.' . $field, true)),
                ],
            ];
        };
        $number = function ($field) {
            return [
                'number' => [
                    'if_set' => true,
                    'rule' => function ($value) {
                        return $value === '' || (is_numeric($value) && $value >= 0);
                    },
                    'message' => Language::_('Featherpanel.!error.package.number', true, Language::_('Featherpanel.package.' . $field, true)),
                ],
            ];
        };
        $rules = [
            'product_type' => [
                'valid' => [
                    'rule' => ['in_array', ['game', 'vds', 'webspace']],
                    'message' => Language::_('Featherpanel.!error.product_type.valid', true),
                ],
            ],
        ];
        switch ($type) {
            case 'game':
                $rules += ['realm_id' => $required('realm_id'), 'spell_id' => $required('spell_id')];
                foreach (['memory', 'swap', 'disk', 'cpu', 'io', 'database_limit', 'backup_limit', 'allocation_limit'] as $f) {
                    $rules[$f] = $number($f);
                }
                $rules['memory'] += ['positive' => [
                    'rule' => function ($v) {
                        return is_numeric($v) && (int) $v > 0;
                    },
                    'message' => Language::_('Featherpanel.!error.package.required', true, Language::_('Featherpanel.package.memory', true)),
                ]];
                break;
            case 'vds':
                $rules += ['vm_node_id' => $required('vm_node_id'), 'template_id' => $required('template_id')];
                foreach (['memory', 'cpus', 'cores', 'disk', 'backup_limit', 'bandwidth_limit_gb'] as $f) {
                    $rules[$f] = $number($f);
                }
                break;
            case 'webspace':
                $rules += ['web_node_id' => $required('web_node_id'), 'webplate_id' => $required('webplate_id')];
                foreach (['disk', 'cpu_limit', 'memory_limit', 'bandwidth_limit_gb', 'database_limit', 'mailbox_limit'] as $f) {
                    $rules[$f] = $number($f);
                }
                break;
        }

        return $rules;
    }

    // ------------------------------------------------------------------ service fields

    public function getAdminAddFields($package, $vars = null)
    {
        return $this->serviceFields($package, $vars, true);
    }

    public function getClientAddFields($package, $vars = null)
    {
        return $this->serviceFields($package, $vars, false);
    }

    public function getAdminEditFields($package, $vars = null)
    {
        return $this->serviceFields($package, $vars, true, true);
    }

    public function getClientEditFields($package, $vars = null)
    {
        return new ModuleFields();
    }

    private function serviceFields($package, $vars, $admin, $edit = false)
    {
        Loader::loadHelpers($this, ['Html']);
        $fields = new ModuleFields();
        $vars = (object) ($vars ?: []);
        $type = $this->packageType($package);
        $key = $this->labelInputKey($type);

        $label = $fields->label(Language::_('Featherpanel.service.' . $key, true), 'featherpanel_' . $key);
        $label->attach($fields->fieldText($key, $vars->{$key} ?? ($vars->{self::FIELD_LABEL} ?? null), ['id' => 'featherpanel_' . $key]));
        $label->attach($fields->tooltip(Language::_('Featherpanel.service.tooltip.' . $key, true)));
        $fields->setField($label);

        if ($type === 'vds' && !$edit) {
            $pw = $fields->label(Language::_('Featherpanel.service.password', true), 'featherpanel_password');
            $pw->attach($fields->fieldPassword('password', ['id' => 'featherpanel_password', 'value' => $vars->password ?? '']));
            $pw->attach($fields->tooltip(Language::_('Featherpanel.service.tooltip.password', true)));
            $fields->setField($pw);
        }

        if ($admin && $edit) {
            foreach ([self::FIELD_ID, self::FIELD_UUID, self::FIELD_EXTERNAL_ID] as $ref) {
                $f = $fields->label(Language::_('Featherpanel.service.' . $ref, true), $ref);
                $f->attach($fields->fieldText($ref, $vars->{$ref} ?? null, ['id' => $ref]));
                $fields->setField($f);
            }
        }

        return $fields;
    }

    private function labelInputKey($type)
    {
        return $type === 'vds' ? 'hostname' : ($type === 'webspace' ? 'domain' : 'server_name');
    }

    public function validateService($package, ?array $vars = null)
    {
        $type = $this->packageType($package);
        $this->Input->setRules($this->getServiceRules($type, $vars ?? []));

        return $this->Input->validates($vars);
    }

    public function validateServiceEdit($service, ?array $vars = null)
    {
        $type = $this->packageType($service->package ?? null);
        $this->Input->setRules($this->getServiceRules($type, $vars ?? [], true));

        return $this->Input->validates($vars);
    }

    private function getServiceRules($type, array $vars, $edit = false)
    {
        $rules = [];
        if ($type === 'webspace') {
            $rules['domain'] = [
                'format' => [
                    'if_set' => $edit,
                    'rule' => function ($domain) {
                        return FeatherpanelPayloads::domain($domain) !== '';
                    },
                    'message' => Language::_('Featherpanel.!error.domain.format', true),
                ],
            ];
        } elseif ($type === 'vds') {
            $rules['hostname'] = [
                'format' => [
                    'if_set' => true,
                    'rule' => ['matches', '/^[A-Za-z0-9]([A-Za-z0-9.-]{0,61}[A-Za-z0-9])?$/'],
                    'message' => Language::_('Featherpanel.!error.hostname.format', true),
                ],
            ];
            $rules['password'] = [
                'length' => [
                    'if_set' => true,
                    'rule' => function ($pw) {
                        return $pw === '' || $pw === null || strlen((string) $pw) >= 8;
                    },
                    'message' => Language::_('Featherpanel.!error.password.length', true),
                ],
            ];
        } else {
            $rules['server_name'] = [
                'length' => [
                    'if_set' => true,
                    'rule' => ['maxLength', 191],
                    'message' => Language::_('Featherpanel.!error.server_name.length', true),
                ],
            ];
        }

        return $rules;
    }

    // ------------------------------------------------------------------ lifecycle

    public function addService($package, ?array $vars = null, $parent_package = null, $parent_service = null, $status = 'pending')
    {
        $vars = $vars ?? [];
        $type = $this->packageType($package);
        $row = $this->rowForPackage($package);
        $labelKey = $this->labelInputKey($type);
        if (!isset($vars[$labelKey]) && isset($vars[self::FIELD_LABEL])) {
            // Activation of a pending order: Blesta passes the stored service fields.
            $vars[$labelKey] = $vars[self::FIELD_LABEL];
        }

        if (!$this->validateService($package, $vars)) {
            return;
        }

        // On activation of a pending order Blesta passes the stored service fields instead of the order input.
        $label = trim((string) ($vars[$labelKey] ?? ($vars[self::FIELD_LABEL] ?? '')));
        $password = (string) ($vars['password'] ?? ($vars['vds_password'] ?? ''));
        $fields = [
            self::FIELD_TYPE => $type,
            self::FIELD_LABEL => $label,
        ];

        if (($vars['use_module'] ?? 'false') !== 'true') {
            if ($type === 'vds' && $password !== '') {
                $fields['vds_password'] = $password;
            }

            return $this->toFieldArray($fields);
        }
        if (!$row) {
            $this->Input->setErrors(['module_row' => ['missing' => Language::_('Featherpanel.!error.no_row', true)]]);

            return;
        }

        try {
            $provisioner = $this->provisioner($row);
            $clientId = (int) ($vars['client_id'] ?? 0);
            $client = $this->clientDetails($clientId);
            $userUuid = $this->clientMeta($clientId, self::META_USER_UUID);
            $user = $provisioner->findOrCreateUser($client + ['known_uuid' => $userUuid]);
            $this->rememberPanelUser($clientId, $user);

            $serviceId = $this->resolveServiceId($vars, $package);
            $externalId = $this->externalId($row, $clientId, $serviceId, $vars);
            $config = $this->resolveConfig($package, $vars);
            $input = [
                'name' => $type === 'game' ? $fields[self::FIELD_LABEL] : '',
                'hostname' => $type === 'vds' ? $fields[self::FIELD_LABEL] : '',
                'domain' => $type === 'webspace' ? $fields[self::FIELD_LABEL] : '',
                'password' => $password,
            ];
            if ($input['name'] === '' && $type === 'game') {
                $input['name'] = $package->name ?? '';
            }

            $resource = $provisioner->create($type, $config, $input, $user, $externalId);
        } catch (FeatherpanelProvisionException $e) {
            $this->Input->setErrors(['api' => ['internal' => $e->getMessage()]]);

            return;
        }

        return $this->toFieldArray($this->fieldsFromResource($type, $resource, $fields + [
            self::FIELD_EXTERNAL_ID => $externalId,
            self::FIELD_USER_UUID => $user['uuid'],
        ]));
    }

    public function editService($package, $service, ?array $vars = null, $parent_package = null, $parent_service = null)
    {
        $vars = $vars ?? [];
        $current = $this->serviceFieldArray($service);
        $type = $current[self::FIELD_TYPE] ?? $this->packageType($package);

        if (!$this->validateServiceEdit($service, $vars)) {
            return;
        }

        $fields = $current;
        $labelKey = $this->labelInputKey($type);
        if (isset($vars[$labelKey]) && trim($vars[$labelKey]) !== '') {
            $fields[self::FIELD_LABEL] = trim($vars[$labelKey]);
        }
        // Admins may relink a service to an existing panel resource.
        foreach ([self::FIELD_ID, self::FIELD_UUID, self::FIELD_EXTERNAL_ID] as $key) {
            if (array_key_exists($key, $vars)) {
                $fields[$key] = trim((string) $vars[$key]);
            }
        }

        if (($vars['use_module'] ?? 'false') === 'true') {
            $row = $this->rowForService($service, $package);
            try {
                $provisioner = $this->provisioner($row);
                $ref = $this->refFromFields($fields);
                $changes = [];
                if (($fields[self::FIELD_LABEL] ?? '') !== ($current[self::FIELD_LABEL] ?? '')) {
                    $changes = [
                        'name' => $fields[self::FIELD_LABEL],
                        'hostname' => $fields[self::FIELD_LABEL],
                        'domain' => $fields[self::FIELD_LABEL],
                    ];
                }
                $resource = $provisioner->update($type, $ref, $changes);
                if (!empty($vars['configoptions'])) {
                    $resource = $provisioner->changePackage($type, $ref, $this->resolveConfig($package, $vars));
                }
                $fields = $this->fieldsFromResource($type, $resource, $fields);
            } catch (FeatherpanelProvisionException $e) {
                $this->Input->setErrors(['api' => ['internal' => $e->getMessage()]]);

                return;
            }
        }

        return $this->toFieldArray($fields);
    }

    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->lifecycle($package, $service, function (FeatherpanelProvisioner $p, $type, $ref) use ($service) {
            $reason = !empty($service->suspension_reason) ? $service->suspension_reason : 'Suspended by billing (service #' . ($service->id ?? '?') . ')';

            return $p->suspend($type, $ref, $reason);
        });
    }

    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->lifecycle($package, $service, function (FeatherpanelProvisioner $p, $type, $ref) {
            return $p->unsuspend($type, $ref);
        });
    }

    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->lifecycle($package, $service, function (FeatherpanelProvisioner $p, $type, $ref) {
            $p->terminate($type, $ref);

            return null;
        });
    }

    public function changeServicePackage($package_from, $package_to, $service, $parent_package = null, $parent_service = null)
    {
        $fromType = $this->packageType($package_from);
        $toType = $this->packageType($package_to);
        if ($fromType !== $toType) {
            $this->Input->setErrors(['package' => ['type' => Language::_('Featherpanel.!error.package.type_change', true)]]);

            return;
        }

        return $this->lifecycle($package_to, $service, function (FeatherpanelProvisioner $p, $type, $ref) use ($package_to, $service) {
            return $p->changePackage($type, $ref, $this->resolveConfig($package_to, ['configoptions' => $this->serviceOptions($service)]));
        });
    }

    public function renewService($package, $service, $parent_package = null, $parent_service = null)
    {
        return null;
    }

    /**
     * Runs one lifecycle action and returns the refreshed service fields.
     */
    private function lifecycle($package, $service, callable $action)
    {
        $fields = $this->serviceFieldArray($service);
        $type = $fields[self::FIELD_TYPE] ?? $this->packageType($package);
        $row = $this->rowForService($service, $package);
        if (!$row) {
            $this->Input->setErrors(['module_row' => ['missing' => Language::_('Featherpanel.!error.no_row', true)]]);

            return;
        }
        try {
            $resource = $action($this->provisioner($row), $type, $this->refFromFields($fields));
        } catch (FeatherpanelProvisionException $e) {
            $this->Input->setErrors(['api' => ['internal' => $e->getMessage()]]);

            return;
        }
        if (is_array($resource)) {
            return $this->toFieldArray($this->fieldsFromResource($type, $resource, $fields));
        }

        return null;
    }

    // ------------------------------------------------------------------ info + tabs

    public function getAdminServiceInfo($service, $package)
    {
        return $this->serviceInfoView('admin_service_info', $service, $package);
    }

    public function getClientServiceInfo($service, $package)
    {
        return $this->serviceInfoView('client_service_info', $service, $package);
    }

    private function serviceInfoView($view, $service, $package)
    {
        $this->view = $this->makeView($view);
        Loader::loadHelpers($this, ['Form', 'Html']);
        $fields = $this->serviceFieldArray($service);
        $row = $this->rowForService($service, $package);
        $this->view->set('fields', (object) $fields);
        $this->view->set('panel_url', $row ? $row->meta->panel_url : '');
        $this->view->set('username', $this->clientMeta($service->client_id, self::META_USERNAME));
        $this->view->set('password', $this->clientMeta($service->client_id, self::META_PASSWORD));

        return $this->view->fetch();
    }

    public function getAdminTabs($package)
    {
        return ['tabAdminManage' => Language::_('Featherpanel.tab.manage', true)];
    }

    public function getClientTabs($package)
    {
        return ['tabClientManage' => ['name' => Language::_('Featherpanel.tab.manage', true), 'icon' => 'fas fa-server']];
    }

    public function tabAdminManage($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageTab('tab_admin_manage', $package, $service, $post ?? []);
    }

    public function tabClientManage($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->manageTab('tab_client_manage', $package, $service, $post ?? []);
    }

    /**
     * Status card plus a "log in to the panel" button that creates a short-lived SSO link.
     */
    private function manageTab($view, $package, $service, array $post)
    {
        $this->view = $this->makeView($view);
        Loader::loadHelpers($this, ['Form', 'Html']);
        $fields = $this->serviceFieldArray($service);
        $type = $fields[self::FIELD_TYPE] ?? $this->packageType($package);
        $row = $this->rowForService($service, $package);
        $resource = null;
        $loginUrl = null;
        $error = null;

        if ($row && in_array($service->status, ['active', 'suspended'], true)) {
            try {
                $provisioner = $this->provisioner($row);
                $ref = $this->refFromFields($fields);
                $resource = $provisioner->powerState($type, $ref);
                if ($resource !== null && empty($resource['pending']) && !empty($post['featherpanel_login'])) {
                    if ($service->status !== 'active') {
                        $error = Language::_('Featherpanel.!error.login.suspended', true);
                    } else {
                        $userUuid = $fields[self::FIELD_USER_UUID] ?? $this->clientMeta($service->client_id, self::META_USER_UUID);
                        $loginUrl = $provisioner->loginUrl($userUuid, $type, $resource, $row->meta->panel_url);
                    }
                }
            } catch (FeatherpanelProvisionException $e) {
                $error = $e->getMessage();
            }
        }

        $this->view->set('type', $type);
        $this->view->set('service', $service);
        $this->view->set('fields', (object) $fields);
        $this->view->set('resource', $resource);
        $this->view->set('login_url', $loginUrl);
        $this->view->set('error', $error);
        $this->view->set('panel_url', $row ? $row->meta->panel_url : '');

        return $this->view->fetch();
    }

    // ------------------------------------------------------------------ helpers

    private function makeView($name)
    {
        $view = new View($name, 'default');
        $view->base_uri = $this->base_uri;
        $view->setDefaultView('components' . DS . 'modules' . DS . 'featherpanel' . DS);

        return $view;
    }

    private function packageType($package)
    {
        $type = $package->meta->product_type ?? 'game';

        return in_array($type, ['game', 'vds', 'webspace'], true) ? $type : 'game';
    }

    private function rowForPackage($package)
    {
        if (!empty($package->module_group)) {
            $rowId = $this->selectModuleRow($package->module_group);
        } else {
            $rowId = $package->module_row ?? 0;
        }
        $row = $rowId ? $this->getModuleRow($rowId) : null;
        if ($row) {
            $this->setModuleRow($row);
        }

        return $row ?: null;
    }

    private function rowForService($service, $package)
    {
        $row = !empty($service->module_row_id) ? $this->getModuleRow($service->module_row_id) : null;
        if (!$row) {
            return $this->rowForPackage($package);
        }
        $this->setModuleRow($row);

        return $row;
    }

    private function buildApi($meta)
    {
        $meta = (object) $meta;
        $api = new FeatherpanelApi(
            $meta->panel_url ?? '',
            $meta->api_key ?? '',
            ($meta->verify_ssl ?? 'true') === 'true'
        );
        $api->setLogger(function ($method, $path, $body, FeatherpanelApiResponse $response) use ($meta) {
            $url = rtrim($meta->panel_url ?? '', '/') . $path;
            $this->log($url, $method . ' ' . json_encode(self::maskSecrets($body)), 'input', true);
            $this->log($url, self::maskSecretsInRaw((string) $response->raw ?: (string) $response->transportError), 'output', $response->ok());
        });

        return $api;
    }

    private function buildProvisioner($meta)
    {
        if ($this->provisionerOverride !== null) {
            return $this->provisionerOverride;
        }

        return new FeatherpanelProvisioner($this->buildApi($meta), [
            'vds_wait_seconds' => (int) ($meta->vds_wait_seconds ?? 60),
        ]);
    }

    private function provisioner($row)
    {
        return $this->buildProvisioner($row->meta);
    }

    /** Passwords never go into the Blesta module log. */
    public static function maskSecrets($body)
    {
        if (!is_array($body)) {
            return $body;
        }
        foreach ($body as $key => $value) {
            if (is_array($value)) {
                $body[$key] = self::maskSecrets($value);
            } elseif (preg_match('/password|secret|token/i', (string) $key)) {
                $body[$key] = '***';
            }
        }

        return $body;
    }

    public static function maskSecretsInRaw($raw)
    {
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $raw = json_encode(self::maskSecrets(self::pruneForLog($decoded)), JSON_UNESCAPED_SLASHES);
        } else {
            $raw = preg_replace('/("[A-Za-z_]*(?:password|secret|token)[A-Za-z_]*"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i', '$1"***"', (string) $raw);
        }

        return strlen($raw) > 20000 ? substr($raw, 0, 20000) . '...' : $raw;
    }

    /**
     * Drops mail bodies and activity history (the panel returns them with user details and they
     * can contain the credentials sent to the client) before a response is logged.
     */
    private static function pruneForLog(array $data)
    {
        foreach ($data as $key => $value) {
            if (in_array((string) $key, ['mails', 'activities', 'body', 'html', 'last_crash_log'], true)) {
                $data[$key] = '[omitted]';
            } elseif (is_array($value)) {
                $data[$key] = self::pruneForLog($value);
            }
        }

        return $data;
    }

    private function resolveConfig($package, array $vars)
    {
        $type = $this->packageType($package);
        $meta = isset($package->meta) ? (array) $package->meta : [];

        return FeatherpanelPayloads::resolveConfig($type, $meta, (array) ($vars['configoptions'] ?? []));
    }

    private function serviceOptions($service)
    {
        $options = [];
        foreach ($service->options ?? [] as $option) {
            $value = isset($option->option_value) && $option->option_value !== '' ? $option->option_value : ($option->qty ?? null);
            $options[$option->option_name] = $value;
        }

        return $options;
    }

    private function clientDetails($clientId)
    {
        if (!isset($this->Clients)) {
            Loader::loadModels($this, ['Clients']);
        }
        $client = $this->Clients->get($clientId, false);
        if (!$client || empty($client->email)) {
            throw new FeatherpanelProvisionException('The Blesta client ' . $clientId . ' has no email address.');
        }

        return [
            'email' => $client->email,
            'first_name' => $client->first_name ?? '',
            'last_name' => $client->last_name ?? '',
        ];
    }

    private function clientMeta($clientId, $key)
    {
        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }
        $module = $this->getModule();
        $meta = $this->ModuleClientMeta->get($clientId, $key, $module->id ?? 0);

        return $meta ? $meta->value : null;
    }

    private function rememberPanelUser($clientId, array $user)
    {
        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }
        $module = $this->getModule();
        $meta = [
            ['key' => self::META_USER_UUID, 'value' => $user['uuid'], 'encrypted' => 0],
            ['key' => self::META_USERNAME, 'value' => $user['username'] ?? '', 'encrypted' => 0],
        ];
        if (!empty($user['created']) && !empty($user['password'])) {
            $meta[] = ['key' => self::META_PASSWORD, 'value' => $user['password'], 'encrypted' => 1];
        }
        $this->ModuleClientMeta->set($clientId, $module->id ?? 0, 0, $meta);
    }

    /**
     * Blesta calls addService before the service row exists (and again on activation without
     * passing its id). Find the matching pending service so the panel key is stable per service.
     */
    private function resolveServiceId(array $vars, $package)
    {
        if (!empty($vars['service_id'])) {
            return (int) $vars['service_id'];
        }
        if (empty($vars['client_id']) || empty($vars['pricing_id'])) {
            return null;
        }
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }
        $rows = $this->Record->select(['services.id'])->from('services')
            ->where('services.client_id', '=', (int) $vars['client_id'])
            ->where('services.pricing_id', '=', (int) $vars['pricing_id'])
            ->where('services.status', 'in', ['pending', 'in_review'])
            ->fetchAll();
        $candidates = [];
        foreach ($rows as $row) {
            $hasRef = $this->Record->select(['key'])->from('service_fields')
                ->where('service_id', '=', $row->id)
                ->where('key', 'in', [self::FIELD_ID, self::FIELD_UUID, self::FIELD_CREATION_ID])
                ->where('value', '!=', '')
                ->numResults();
            if (!$hasRef) {
                $candidates[] = (int) $row->id;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function externalId($row, $clientId, $serviceId, array $vars)
    {
        $prefix = $row->meta->external_id_prefix ?? 'blesta-';
        if ($serviceId) {
            return $prefix . 'svc-' . $serviceId;
        }
        if (!empty($vars[self::FIELD_EXTERNAL_ID])) {
            return $vars[self::FIELD_EXTERNAL_ID];
        }

        return $prefix . 'c' . (int) $clientId . '-' . bin2hex(random_bytes(6));
    }

    private function serviceFieldArray($service)
    {
        $out = [];
        foreach ($service->fields ?? [] as $field) {
            $out[$field->key] = $field->value;
        }

        return $out;
    }

    private function refFromFields(array $fields)
    {
        return [
            'id' => !empty($fields[self::FIELD_ID]) ? (int) $fields[self::FIELD_ID] : null,
            'uuid' => !empty($fields[self::FIELD_UUID]) ? $fields[self::FIELD_UUID] : null,
            'external_id' => !empty($fields[self::FIELD_EXTERNAL_ID]) ? $fields[self::FIELD_EXTERNAL_ID] : null,
            'creation_id' => !empty($fields[self::FIELD_CREATION_ID]) ? $fields[self::FIELD_CREATION_ID] : null,
        ];
    }

    private function fieldsFromResource($type, array $resource, array $fields)
    {
        $fields[self::FIELD_TYPE] = $type;
        foreach (['id' => self::FIELD_ID, 'uuid' => self::FIELD_UUID, 'creation_id' => self::FIELD_CREATION_ID, 'external_id' => self::FIELD_EXTERNAL_ID] as $from => $to) {
            if (!empty($resource[$from])) {
                $fields[$to] = (string) $resource[$from];
            }
        }
        if (!empty($resource['name'])) {
            $fields[self::FIELD_LABEL] = $resource['name'];
        }
        if (!empty($resource['ip'])) {
            $fields['featherpanel_ip'] = $resource['ip'];
        }
        if (!empty($resource['port'])) {
            $fields['featherpanel_port'] = (string) $resource['port'];
        }
        if ($type === 'webspace' && !empty($resource['domains'])) {
            $fields['featherpanel_domain'] = $resource['domains'][0];
        }
        if ($type === 'vds') {
            if (!empty($resource['password'])) {
                $fields['featherpanel_vds_password'] = $resource['password'];
            }
            if (!empty($resource['ci_user'])) {
                $fields['featherpanel_vds_user'] = $resource['ci_user'];
            }
        }
        $fields['featherpanel_pending'] = !empty($resource['pending']) ? '1' : '0';

        return $fields;
    }

    private function toFieldArray(array $fields)
    {
        $out = [];
        foreach ($fields as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'value' => (string) $value,
                'encrypted' => in_array($key, ['featherpanel_vds_password', 'vds_password'], true) ? 1 : 0,
            ];
        }

        return $out;
    }

    private function addSelect(ModuleFields $fields, $key, array $options, $value)
    {
        $label = $fields->label(Language::_('Featherpanel.package.' . $key, true), 'featherpanel_' . $key);
        $label->attach($fields->fieldSelect('meta[' . $key . ']', $options, $value, ['id' => 'featherpanel_' . $key]));
        $tip = Language::_('Featherpanel.package.tooltip.' . $key, true);
        if ($tip !== '' && $tip !== 'Featherpanel.package.tooltip.' . $key) {
            $label->attach($fields->tooltip($tip));
        }
        $fields->setField($label);
    }

    private function addText(ModuleFields $fields, $key, $value)
    {
        $label = $fields->label(Language::_('Featherpanel.package.' . $key, true), 'featherpanel_' . $key);
        $label->attach($fields->fieldText('meta[' . $key . ']', $value, ['id' => 'featherpanel_' . $key]));
        $tip = Language::_('Featherpanel.package.tooltip.' . $key, true);
        if ($tip !== '' && $tip !== 'Featherpanel.package.tooltip.' . $key) {
            $label->attach($fields->tooltip($tip));
        }
        $fields->setField($label);
    }

    private function addTextarea(ModuleFields $fields, $key, $value)
    {
        $label = $fields->label(Language::_('Featherpanel.package.' . $key, true), 'featherpanel_' . $key);
        $label->attach($fields->fieldTextarea('meta[' . $key . ']', $value, ['id' => 'featherpanel_' . $key]));
        $label->attach($fields->tooltip(Language::_('Featherpanel.package.tooltip.' . $key, true)));
        $fields->setField($label);
    }

    private function addCheckbox(ModuleFields $fields, $key, $value)
    {
        $label = $fields->label(Language::_('Featherpanel.package.' . $key, true), 'featherpanel_' . $key, ['class' => 'inline']);
        $label->attach($fields->fieldCheckbox('meta[' . $key . ']', '1', in_array((string) $value, ['1', 'true'], true), ['id' => 'featherpanel_' . $key, 'class' => 'inline']));
        $fields->setField($label);
    }
}
