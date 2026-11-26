<?php
/**
 * Builds FeatherPanel admin API request bodies from Blesta package settings and order input.
 * Pure functions only, so they are easy to test.
 */
class FeatherpanelPayloads
{
    /** Package settings per product type: key => [default, cast] */
    public static function packageKeys($type)
    {
        switch ($type) {
            case 'game':
                return [
                    'location_id' => ['', 'int_or_empty'], 'node_id' => ['', 'int_or_empty'],
                    'realm_id' => ['', 'int'], 'spell_id' => ['', 'int'],
                    'memory' => [1024, 'int'], 'swap' => [0, 'int'], 'disk' => [5120, 'int'], 'io' => [500, 'int'],
                    'cpu' => [100, 'int'], 'threads' => ['', 'string'],
                    'database_limit' => [0, 'int'], 'backup_limit' => [0, 'int'], 'allocation_limit' => [0, 'int'],
                    'image' => ['', 'string'], 'startup' => ['', 'string'], 'environment' => ['', 'string'],
                    'oom_killer' => ['1', 'bool'], 'skip_scripts' => ['0', 'bool'],
                ];
            case 'vds':
                return [
                    'vm_node_id' => ['', 'int'], 'template_id' => ['', 'int'],
                    'memory' => [1024, 'int'], 'cpus' => [1, 'int'], 'cores' => [1, 'int'], 'disk' => [20, 'int'],
                    'storage' => ['', 'string'], 'bridge' => ['', 'string'], 'pve_node' => ['', 'string'],
                    'backup_limit' => [0, 'int'], 'bandwidth_limit_gb' => ['', 'int_or_empty'],
                    'ci_user' => ['root', 'string'], 'on_boot' => ['1', 'bool'],
                ];
            case 'webspace':
                return [
                    'web_node_id' => ['', 'int'], 'webplate_id' => ['', 'int'], 'hosting_package_id' => ['', 'int_or_empty'],
                    'disk' => [1024, 'int'], 'cpu_limit' => [0, 'float'], 'memory_limit' => [0, 'int'],
                    'bandwidth_limit_gb' => ['', 'int_or_empty'], 'database_limit' => [1, 'int'], 'mailbox_limit' => [0, 'int'],
                    'ssl' => ['1', 'bool'],
                ];
        }

        return [];
    }

    /**
     * Merges package meta with config options (config options win) and casts values.
     *
     * @param string $type
     * @param array $meta Package meta (key => value)
     * @param array $configOptions Blesta config options (option name => value)
     */
    public static function resolveConfig($type, array $meta, array $configOptions = [])
    {
        $out = [];
        foreach (self::packageKeys($type) as $key => list($default, $cast)) {
            $value = array_key_exists($key, $meta) ? $meta[$key] : $default;
            if (array_key_exists($key, $configOptions) && $configOptions[$key] !== '' && $configOptions[$key] !== null) {
                $value = $configOptions[$key];
            }
            $out[$key] = self::cast($value, $cast);
        }

        return $out;
    }

    private static function cast($value, $cast)
    {
        switch ($cast) {
            case 'int':
                return is_numeric($value) ? (int) $value : 0;
            case 'int_or_empty':
                return ($value === '' || $value === null || !is_numeric($value)) ? null : (int) $value;
            case 'float':
                return is_numeric($value) ? (float) $value : 0.0;
            case 'bool':
                return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
            default:
                return trim((string) $value);
        }
    }

    /**
     * Parses "KEY=value" lines (or a JSON object) into an env map.
     */
    public static function parseEnvironment($text)
    {
        if (is_array($text)) {
            return array_map('strval', $text);
        }
        $text = trim((string) $text);
        if ($text === '') {
            return [];
        }
        if ($text[0] === '{') {
            $json = json_decode($text, true);
            if (is_array($json)) {
                return array_map('strval', $json);
            }
        }
        $env = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            list($key, $value) = explode('=', $line, 2);
            $key = strtoupper(trim($key));
            if (preg_match('/^[A-Z0-9_]+$/', $key)) {
                $env[$key] = trim($value);
            }
        }

        return $env;
    }

    public static function gameCreate(array $config, array $service, array $user, array $spell, array $spellVariables, $externalId)
    {
        $image = $config['image'] !== '' ? $config['image'] : (string) ($spell['default_docker_image'] ?? '');
        if ($image === '' && !empty($spell['docker_images'])) {
            $images = is_array($spell['docker_images']) ? $spell['docker_images'] : json_decode((string) $spell['docker_images'], true);
            if (is_array($images) && !empty($images)) {
                $image = (string) reset($images);
            }
        }
        $startup = $config['startup'] !== '' ? $config['startup'] : (string) ($spell['startup'] ?? '');

        // Spell defaults, then package environment, then values the client entered.
        $variables = [];
        foreach ($spellVariables as $variable) {
            if (!empty($variable['env_variable'])) {
                $variables[$variable['env_variable']] = (string) ($variable['default_value'] ?? '');
            }
        }
        $variables = array_merge($variables, self::parseEnvironment($config['environment'] ?? ''));
        foreach (self::parseEnvironment($service['environment'] ?? []) as $key => $value) {
            if ($value !== '' && array_key_exists($key, $variables)) {
                $variables[$key] = $value;
            }
        }

        $name = trim((string) ($service['name'] ?? ''));
        $payload = [
            'name' => $name !== '' ? substr($name, 0, 191) : 'Server ' . $externalId,
            'description' => 'Managed by Blesta (' . $externalId . ')',
            'owner_id' => (int) $user['id'],
            'realms_id' => (int) $config['realm_id'],
            'spell_id' => (int) $config['spell_id'],
            'image' => $image,
            'startup' => $startup,
            'variables' => (object) $variables,
            'external_id' => $externalId,
            'skip_scripts' => $config['skip_scripts'] ? 1 : 0,
            'oom_killer' => (bool) $config['oom_killer'],
        ];

        return array_merge($payload, self::gameLimits($config));
    }

    public static function gameLimits(array $config)
    {
        $limits = [
            'memory' => (int) $config['memory'],
            'swap' => (int) $config['swap'],
            'disk' => (int) $config['disk'],
            'io' => min(1000, max(10, (int) $config['io'])),
            'cpu' => (int) $config['cpu'],
            'database_limit' => (int) $config['database_limit'],
            'backup_limit' => (int) $config['backup_limit'],
            'allocation_limit' => (int) $config['allocation_limit'],
        ];
        if (isset($config['threads']) && $config['threads'] !== '') {
            $limits['threads'] = (string) $config['threads'];
        }

        return $limits;
    }

    public static function vdsCreate(array $config, array $service, array $user, $externalId)
    {
        $hostname = self::hostname($service['hostname'] ?? '', $externalId);
        $password = (string) ($service['password'] ?? '');
        if ($password === '') {
            $password = FeatherpanelProvisioner::randomPassword(16);
        }
        $payload = [
            'vm_node_id' => (int) $config['vm_node_id'],
            'template_id' => (int) $config['template_id'],
            'user_uuid' => (string) $user['uuid'],
            'hostname' => $hostname,
            'ci_user' => $config['ci_user'] !== '' ? $config['ci_user'] : 'root',
            'ci_password' => $password,
            'notes' => 'Managed by Blesta (' . $externalId . ')',
            'external_id' => $externalId,
            'on_boot' => $config['on_boot'] ? 1 : 0,
            'backup_limit' => (int) $config['backup_limit'],
        ];
        foreach (['storage', 'bridge', 'pve_node'] as $key) {
            if (!empty($config[$key])) {
                $payload[$key] = $config[$key];
            }
        }

        return array_merge($payload, self::vdsLimits($config), ['disk' => (int) $config['disk']]);
    }

    public static function vdsLimits(array $config)
    {
        $limits = [
            'memory' => (int) $config['memory'],
            'cpus' => max(1, (int) $config['cpus']),
            'cores' => max(1, (int) $config['cores']),
        ];
        if ($config['bandwidth_limit_gb'] !== null) {
            $limits['bandwidth_limit_gb'] = (int) $config['bandwidth_limit_gb'];
        }

        return $limits;
    }

    public static function webspaceCreate(array $config, array $service, array $user, $externalId, $uuid)
    {
        $domain = self::domain($service['domain'] ?? '');
        $payload = [
            'uuid' => $uuid,
            'external_id' => $externalId,
            'name' => $domain !== '' ? $domain : 'Site ' . $externalId,
            'description' => 'Managed by Blesta (' . $externalId . ')',
            'web_node_id' => (int) $config['web_node_id'],
            'webplate_id' => (int) $config['webplate_id'],
            'owner_id' => (int) $user['id'],
            'domains' => $domain !== '' ? [$domain] : [],
            'ssl' => (bool) $config['ssl'] && $domain !== '',
            'start_on_completion' => true,
        ];
        if (!empty($config['hosting_package_id'])) {
            $payload['hosting_package_id'] = (int) $config['hosting_package_id'];
        }

        return array_merge($payload, self::webspaceLimits($config, false));
    }

    public static function webspaceLimits(array $config, $includePlate = true)
    {
        $limits = [
            'disk' => max(1, (int) $config['disk']),
            'cpu_limit' => (float) $config['cpu_limit'],
            'memory_limit' => (int) $config['memory_limit'],
            'bandwidth_limit_gb' => $config['bandwidth_limit_gb'],
            'database_limit' => (int) $config['database_limit'],
            'mailbox_limit' => (int) $config['mailbox_limit'],
        ];
        if ($includePlate && !empty($config['webplate_id'])) {
            $limits['webplate_id'] = (int) $config['webplate_id'];
        }
        if ($includePlate && !empty($config['hosting_package_id'])) {
            $limits['hosting_package_id'] = (int) $config['hosting_package_id'];
        }

        return $limits;
    }

    public static function edit($type, array $changes)
    {
        $body = [];
        switch ($type) {
            case 'game':
                if (isset($changes['name']) && trim($changes['name']) !== '') {
                    $body['name'] = substr(trim($changes['name']), 0, 191);
                }
                break;
            case 'vds':
                if (isset($changes['hostname']) && trim($changes['hostname']) !== '') {
                    $body['hostname'] = self::hostname($changes['hostname'], 'vds');
                }
                break;
            case 'webspace':
                if (isset($changes['domain']) && self::domain($changes['domain']) !== '') {
                    $body['domains'] = [self::domain($changes['domain'])];
                }
                break;
        }

        return $body;
    }

    public static function hostname($value, $fallback)
    {
        $host = strtolower(trim((string) $value));
        $host = preg_replace('/[^a-z0-9.-]+/', '-', $host);
        $host = trim((string) $host, '.-');
        if ($host === '') {
            $host = strtolower(preg_replace('/[^a-z0-9-]+/i', '-', (string) $fallback));
        }

        return substr($host, 0, 63);
    }

    public static function domain($value)
    {
        $domain = strtolower(trim((string) $value));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim((string) strtok($domain, '/'), '.');

        return preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) ? $domain : '';
    }
}
