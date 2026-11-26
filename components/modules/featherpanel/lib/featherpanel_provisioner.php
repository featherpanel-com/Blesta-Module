<?php
/**
 * Panel-side provisioning logic for the FeatherPanel Blesta module.
 *
 * Kept free of Blesta classes so it can be unit-tested against a mocked transport.
 * All public methods throw FeatherpanelProvisionException with a readable message on failure.
 */
class FeatherpanelProvisionException extends Exception
{
}

class FeatherpanelProvisioner
{
    const TYPE_GAME = 'game';
    const TYPE_VDS = 'vds';
    const TYPE_WEBSPACE = 'webspace';

    /** @var FeatherpanelApi */
    private $api;
    /** @var int Seconds to wait for an asynchronous VDS clone before returning */
    private $vdsWaitSeconds;
    /** @var callable function(int $seconds) */
    private $sleeper;

    public function __construct(FeatherpanelApi $api, array $options = [])
    {
        $this->api = $api;
        $this->vdsWaitSeconds = isset($options['vds_wait_seconds']) ? max(0, (int) $options['vds_wait_seconds']) : 60;
        $this->sleeper = isset($options['sleep']) && is_callable($options['sleep'])
            ? $options['sleep']
            : function ($seconds) {
                sleep($seconds);
            };
    }

    public static function types()
    {
        return [self::TYPE_GAME, self::TYPE_VDS, self::TYPE_WEBSPACE];
    }

    /**
     * Stable UUID derived from an external id, used as the WebSpace UUID so a retried
     * create finds the WebSpace made by the first attempt.
     */
    public static function deterministicUuid($externalId)
    {
        $hash = sha1('featherpanel-blesta:' . $externalId);
        $timeHi = (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000;
        $clockSeq = (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000;

        return sprintf(
            '%s-%s-%04x-%04x-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            $timeHi,
            $clockSeq,
            substr($hash, 20, 12)
        );
    }

    // ---------------------------------------------------------------- connection

    /**
     * Checks the URL and API key. Returns null when fine, otherwise an error message.
     */
    public function testConnection()
    {
        $response = $this->api->get('/api/admin/users', ['limit' => 1]);
        if ($response->transportError !== null) {
            return 'Could not reach the panel: ' . $response->transportError;
        }
        if ($response->status === 401 || $response->status === 403) {
            return 'The panel rejected the API key (HTTP ' . $response->status . '). Use the private key of an admin API client.';
        }
        if (!$response->ok() || !isset($response->data()['users'])) {
            return 'Unexpected answer from the panel: ' . $response->errorMessage();
        }

        return null;
    }

    // ---------------------------------------------------------------- users

    /**
     * Finds the panel account for a client, creating it when missing.
     *
     * @param array $client email, first_name, last_name, and optionally known_uuid (a uuid stored earlier)
     * @return array id, uuid, username, email, created (bool), password (only when created)
     */
    public function findOrCreateUser(array $client)
    {
        $email = trim((string) ($client['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new FeatherpanelProvisionException('The client has no valid email address.');
        }

        if (!empty($client['known_uuid'])) {
            $response = $this->api->get('/api/admin/users/' . rawurlencode($client['known_uuid']));
            if ($response->ok() && isset($response->data()['user'])) {
                return $this->userResult($response->data()['user'], false);
            }
        }

        $existing = $this->findUserByEmail($email);
        if ($existing !== null) {
            return $this->userResult($existing, false);
        }

        $password = self::randomPassword();
        $base = self::usernameFromEmail($email);
        $lastError = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $username = $attempt === 0 ? $base : substr($base, 0, 26) . '_' . substr(bin2hex(random_bytes(3)), 0, 5);
            $response = $this->api->put('/api/admin/users', [
                'username' => $username,
                'email' => $email,
                'first_name' => self::clip($client['first_name'] ?? '', 64, 'Customer'),
                'last_name' => self::clip($client['last_name'] ?? '', 64, 'Customer'),
                'password' => $password,
            ]);
            if ($response->ok()) {
                $data = $response->data();
                $uuid = isset($data['uuid']) ? $data['uuid'] : null;
                $user = $uuid ? $this->getUser($uuid) : $this->findUserByEmail($email);
                if ($user === null) {
                    throw new FeatherpanelProvisionException('The panel created the account but it could not be read back.');
                }
                $result = $this->userResult($user, true);
                $result['password'] = $password;

                return $result;
            }

            $lastError = $response->errorMessage();
            // Another request may have created the same email meanwhile.
            $existing = $this->findUserByEmail($email);
            if ($existing !== null) {
                return $this->userResult($existing, false);
            }
            if (stripos($lastError, 'username') === false) {
                break;
            }
        }

        throw new FeatherpanelProvisionException('Could not create the panel account: ' . $lastError);
    }

    public function getUser($uuid)
    {
        $response = $this->api->get('/api/admin/users/' . rawurlencode($uuid));

        return $response->ok() && isset($response->data()['user']) ? $response->data()['user'] : null;
    }

    private function findUserByEmail($email)
    {
        $response = $this->api->get('/api/admin/users', ['search' => $email, 'limit' => 50]);
        if (!$response->ok()) {
            throw new FeatherpanelProvisionException('Could not search panel users: ' . $response->errorMessage());
        }
        foreach ($response->data()['users'] ?? [] as $user) {
            if (isset($user['email']) && strcasecmp($user['email'], $email) === 0) {
                return $user;
            }
        }

        return null;
    }

    private function userResult(array $user, $created)
    {
        return [
            'id' => (int) ($user['id'] ?? 0),
            'uuid' => (string) ($user['uuid'] ?? ''),
            'username' => (string) ($user['username'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'created' => (bool) $created,
        ];
    }

    public static function usernameFromEmail($email)
    {
        $local = strtolower((string) strstr($email, '@', true));
        $clean = preg_replace('/[^a-z0-9_]+/', '_', $local);
        $clean = trim((string) $clean, '_');
        if (strlen($clean) < 3) {
            $clean = 'user_' . $clean;
        }

        return substr($clean, 0, 32);
    }

    public static function randomPassword($length = 20)
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < $length - 3; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // Guarantee one of each class panels usually ask for.
        return $password . chr(random_int(65, 90)) . random_int(2, 9) . '!';
    }

    private static function clip($value, $max, $fallback)
    {
        $value = trim((string) $value);
        if ($value === '') {
            $value = $fallback;
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    // ---------------------------------------------------------------- lookups

    /**
     * Finds an existing panel resource for this service.
     *
     * @param string $type game|vds|webspace
     * @param array $ref Stored references: id, uuid, external_id, creation_id
     * @return array|null Normalised resource (see normalise()) or null when it does not exist
     */
    public function find($type, array $ref)
    {
        switch ($type) {
            case self::TYPE_GAME:
                if (!empty($ref['id'])) {
                    $r = $this->lookup('/api/admin/servers/' . (int) $ref['id']);
                    if ($r->ok() && ($server = $this->unwrap($r->data(), 'server')) !== null) {
                        return $this->normalise($type, $server);
                    }
                }
                if (!empty($ref['external_id'])) {
                    $r = $this->lookup('/api/admin/servers/external/' . rawurlencode($ref['external_id']));
                    if ($r->ok() && ($server = $this->unwrap($r->data(), 'server')) !== null) {
                        return $this->normalise($type, $server);
                    }
                }

                return null;

            case self::TYPE_VDS:
                if (!empty($ref['id'])) {
                    $r = $this->lookup('/api/admin/vm-instances/' . (int) $ref['id']);
                    if ($r->ok() && isset($r->data()['instance'])) {
                        return $this->normalise($type, $r->data()['instance']);
                    }
                }
                if (!empty($ref['external_id'])) {
                    $r = $this->lookup('/api/admin/vm-instances/external/' . rawurlencode($ref['external_id']));
                    if ($r->ok()) {
                        $data = $r->data();
                        if (isset($data['instance']) && is_array($data['instance'])) {
                            return $this->normalise($type, $data['instance']);
                        }
                        if (!empty($data['creation_id'])) {
                            return ['type' => $type, 'pending' => true, 'creation_id' => (string) $data['creation_id']];
                        }
                    }
                }
                if (!empty($ref['creation_id'])) {
                    $r = $this->lookup('/api/admin/vm-instances/creation-status/' . rawurlencode($ref['creation_id']));
                    if ($r->ok()) {
                        $data = $r->data();
                        if (($data['status'] ?? '') === 'active' && !empty($data['instance'])) {
                            return $this->normalise($type, $data['instance']);
                        }
                        if (in_array($data['status'] ?? '', ['pending', 'cloning'], true)) {
                            return ['type' => $type, 'pending' => true, 'creation_id' => (string) $ref['creation_id']];
                        }
                    }
                }

                return null;

            case self::TYPE_WEBSPACE:
                $uuids = [];
                if (!empty($ref['uuid'])) {
                    $uuids[] = $ref['uuid'];
                }
                if (!empty($ref['external_id'])) {
                    $uuids[] = self::deterministicUuid($ref['external_id']);
                }
                foreach (array_unique($uuids) as $uuid) {
                    $r = $this->lookup('/api/admin/webspaces/' . rawurlencode($uuid));
                    if ($r->ok()) {
                        $space = $this->unwrapWebspace($r->data());
                        if ($space !== null) {
                            return $this->normalise($type, $space);
                        }
                    }
                }
                if (!empty($ref['external_id'])) {
                    $r = $this->lookup('/api/admin/webspaces/external/' . rawurlencode($ref['external_id']));
                    if ($r->ok() && ($space = $this->unwrapWebspace($r->data())) !== null) {
                        return $this->normalise($type, $space);
                    }
                }

                return null;
        }

        throw new FeatherpanelProvisionException('Unknown product type: ' . $type);
    }

    /**
     * GET used by find(): a 404 (or any other 4xx) means "not there", but an unreachable panel,
     * a rejected key or a 5xx must stop the caller, otherwise a cancel would count as done and a
     * create could run twice.
     */
    private function lookup($path)
    {
        $response = $this->api->get($path);
        if ($response->transportError !== null) {
            throw new FeatherpanelProvisionException('Could not reach the panel: ' . $response->transportError);
        }
        if ($response->status === 401 || $response->status === 403 || $response->status >= 500) {
            throw new FeatherpanelProvisionException('The panel answered HTTP ' . $response->status . ' on ' . $path . ': ' . $response->errorMessage());
        }

        return $response;
    }

    private function unwrapWebspace(array $data)
    {
        $space = $this->unwrap($data, 'webspace');

        return $space !== null && isset($space['uuid']) ? $space : null;
    }

    /**
     * FeatherPanel endpoints return a record either bare or wrapped in a named key.
     */
    private function unwrap(array $data, $key)
    {
        if (isset($data[$key]) && is_array($data[$key])) {
            return $data[$key];
        }

        return isset($data['id']) ? $data : null;
    }

    /**
     * Reduces a panel record to the fields the module stores.
     */
    public function normalise($type, array $row)
    {
        $out = ['type' => $type, 'pending' => false, 'raw' => $row];
        switch ($type) {
            case self::TYPE_GAME:
                $out['id'] = (int) $row['id'];
                $out['uuid'] = (string) ($row['uuid'] ?? '');
                $out['uuid_short'] = (string) ($row['uuidShort'] ?? substr($out['uuid'], 0, 8));
                $out['name'] = (string) ($row['name'] ?? '');
                $out['suspended'] = !empty($row['suspended']) || ($row['status'] ?? '') === 'suspended';
                $out['status'] = (string) ($row['status'] ?? '');
                $out['external_id'] = $row['external_id'] ?? null;
                $out['owner_id'] = isset($row['owner_id']) ? (int) $row['owner_id'] : null;
                $allocation = isset($row['allocation']) && is_array($row['allocation']) ? $row['allocation'] : [];
                $out['ip'] = $allocation['ip_alias'] ?? ($allocation['ip'] ?? ($row['ip'] ?? null));
                $out['port'] = $allocation['port'] ?? ($row['port'] ?? null);
                $out['allocation_id'] = isset($row['allocation_id']) ? (int) $row['allocation_id'] : null;
                break;
            case self::TYPE_VDS:
                $out['id'] = (int) $row['id'];
                $out['vmid'] = isset($row['vmid']) ? (int) $row['vmid'] : null;
                $out['name'] = (string) ($row['hostname'] ?? '');
                $out['ip'] = $row['ip_address'] ?? null;
                $out['suspended'] = !empty($row['suspended']) && (int) $row['suspended'] === 1;
                $out['status'] = (string) ($row['status'] ?? '');
                $out['external_id'] = $row['external_id'] ?? null;
                $out['user_uuid'] = $row['user_uuid'] ?? null;
                $out['disk'] = isset($row['disk_gb']) ? (int) $row['disk_gb'] : null;
                break;
            case self::TYPE_WEBSPACE:
                $out['id'] = (int) ($row['id'] ?? 0);
                $out['uuid'] = (string) $row['uuid'];
                $out['uuid_short'] = (string) ($row['uuidShort'] ?? substr($out['uuid'], 0, 8));
                $out['name'] = (string) ($row['name'] ?? '');
                $out['domains'] = isset($row['domains']) && is_array($row['domains']) ? $row['domains'] : [];
                $out['status'] = (string) ($row['status'] ?? '');
                $out['suspended'] = $out['status'] === 'suspended';
                $out['external_id'] = $row['external_id'] ?? null;
                $out['owner_id'] = isset($row['owner_id']) ? (int) $row['owner_id'] : null;
                break;
        }

        return $out;
    }

    // ---------------------------------------------------------------- create

    /**
     * Creates the panel resource, or returns the one an earlier attempt already made.
     *
     * @param string $type game|vds|webspace
     * @param array $config Package settings with config options applied (see FeatherpanelPayloads)
     * @param array $service Order inputs: name, hostname, domain, password, environment
     * @param array $user Result of findOrCreateUser()
     * @param string $externalId Idempotency key
     * @param array $ref Earlier stored references, if any
     */
    public function create($type, array $config, array $service, array $user, $externalId, array $ref = [])
    {
        $ref['external_id'] = $externalId;
        $existing = $this->find($type, $ref);
        if ($existing !== null) {
            $existing['adopted'] = true;

            return $existing;
        }

        switch ($type) {
            case self::TYPE_GAME:
                return $this->createGame($config, $service, $user, $externalId);
            case self::TYPE_VDS:
                return $this->createVds($config, $service, $user, $externalId);
            case self::TYPE_WEBSPACE:
                return $this->createWebspace($config, $service, $user, $externalId);
        }

        throw new FeatherpanelProvisionException('Unknown product type: ' . $type);
    }

    private function createGame(array $config, array $service, array $user, $externalId)
    {
        $spellId = (int) ($config['spell_id'] ?? 0);
        $realmId = (int) ($config['realm_id'] ?? 0);
        if ($spellId <= 0 || $realmId <= 0) {
            throw new FeatherpanelProvisionException('The package has no realm or spell set.');
        }
        $spell = $this->api->get('/api/admin/spells/' . $spellId);
        if (!$spell->ok() || !isset($spell->data()['spell'])) {
            throw new FeatherpanelProvisionException('Spell ' . $spellId . ' could not be loaded: ' . $spell->errorMessage());
        }
        $spell = $spell->data()['spell'];
        $vars = $this->api->get('/api/admin/spells/' . $spellId . '/variables');
        $spellVariables = $vars->ok() ? ($vars->data()['variables'] ?? []) : [];

        $nodeIds = $this->candidateNodes($config);
        $payload = FeatherpanelPayloads::gameCreate($config, $service, $user, $spell, $spellVariables, $externalId);

        $lastError = 'No node with a free allocation was found.';
        foreach ($nodeIds as $nodeId) {
            $seen = [];
            for ($try = 0; $try < 3; $try++) {
                $allocation = $this->freeAllocation($nodeId, $seen);
                if ($allocation === null) {
                    break;
                }
                $seen[] = (int) $allocation['id'];
                $payload['node_id'] = $nodeId;
                $payload['allocation_id'] = (int) $allocation['id'];

                $response = $this->api->put('/api/admin/servers', $payload);
                if ($response->ok() && !empty($response->data()['server_id'])) {
                    return $this->mustFind(self::TYPE_GAME, ['id' => (int) $response->data()['server_id'], 'external_id' => $externalId]);
                }

                // The panel can fail after the row is written (e.g. sending the welcome mail).
                $existing = $this->find(self::TYPE_GAME, ['external_id' => $externalId]);
                if ($existing !== null) {
                    return $existing;
                }

                $lastError = $response->errorMessage();
                if ($response->errorCode() !== 'ALLOCATION_IN_USE') {
                    throw new FeatherpanelProvisionException('The panel refused the server: ' . $lastError);
                }
            }
        }

        throw new FeatherpanelProvisionException($lastError);
    }

    /** @return int[] */
    private function candidateNodes(array $config)
    {
        if (!empty($config['node_id']) && (int) $config['node_id'] > 0) {
            return [(int) $config['node_id']];
        }
        $query = ['limit' => 100];
        if (!empty($config['location_id'])) {
            $query['location_id'] = (int) $config['location_id'];
        }
        $response = $this->api->get('/api/admin/nodes', $query);
        if (!$response->ok()) {
            throw new FeatherpanelProvisionException('Could not list nodes: ' . $response->errorMessage());
        }
        $ids = [];
        foreach ($response->data()['nodes'] ?? [] as $node) {
            if (!empty($node['maintenance_mode'])) {
                continue;
            }
            if (!empty($config['location_id']) && (int) ($node['location_id'] ?? 0) !== (int) $config['location_id']) {
                continue;
            }
            $ids[] = (int) $node['id'];
        }
        if (empty($ids)) {
            throw new FeatherpanelProvisionException('No node is available for this package.');
        }

        return $ids;
    }

    private function freeAllocation($nodeId, array $skip)
    {
        $response = $this->api->get('/api/admin/allocations/available', ['node_id' => $nodeId, 'limit' => 25]);
        if (!$response->ok()) {
            throw new FeatherpanelProvisionException('Could not list free allocations: ' . $response->errorMessage());
        }
        foreach ($response->data()['allocations'] ?? [] as $allocation) {
            if ((int) ($allocation['node_id'] ?? $nodeId) === (int) $nodeId && !in_array((int) $allocation['id'], $skip, true)) {
                return $allocation;
            }
        }

        return null;
    }

    private function createVds(array $config, array $service, array $user, $externalId)
    {
        $payload = FeatherpanelPayloads::vdsCreate($config, $service, $user, $externalId);
        $response = $this->api->put('/api/admin/vm-instances', $payload);
        $creationId = '';
        if (!$response->ok()) {
            if ($response->status === 409) {
                $existing = $this->find(self::TYPE_VDS, ['external_id' => $externalId]);
                if ($existing !== null) {
                    return $existing;
                }
                // Same external id is still being cloned: follow that job instead of starting another.
                $creationId = (string) ($response->data()['creation_id'] ?? '');
                $payload['ci_password'] = null;
            }
            if ($creationId === '') {
                throw new FeatherpanelProvisionException('The panel refused the VDS: ' . $response->errorMessage());
            }
        } else {
            $creationId = (string) ($response->data()['creation_id'] ?? '');
        }
        if ($creationId === '') {
            throw new FeatherpanelProvisionException('The panel did not return a creation id for the VDS.');
        }

        $waited = 0;
        while (true) {
            $status = $this->api->get('/api/admin/vm-instances/creation-status/' . rawurlencode($creationId));
            $data = $status->data();
            if ($status->ok() && ($data['status'] ?? '') === 'active' && !empty($data['instance'])) {
                $result = $this->normalise(self::TYPE_VDS, $data['instance']);
                $result['creation_id'] = $creationId;
                $result['password'] = $payload['ci_password'] ?? null;

                return $result;
            }
            if ($status->ok() && ($data['status'] ?? '') === 'failed') {
                throw new FeatherpanelProvisionException('The VDS could not be created: ' . ($data['error'] ?? 'unknown error'));
            }
            if ($waited >= $this->vdsWaitSeconds) {
                // Still cloning: hand back the creation id, lookups resolve it later.
                return [
                    'type' => self::TYPE_VDS,
                    'pending' => true,
                    'creation_id' => $creationId,
                    'password' => $payload['ci_password'] ?? null,
                    'name' => $payload['hostname'] ?? '',
                ];
            }
            call_user_func($this->sleeper, 3);
            $waited += 3;
        }
    }

    private function createWebspace(array $config, array $service, array $user, $externalId)
    {
        $uuid = self::deterministicUuid($externalId);
        $payload = FeatherpanelPayloads::webspaceCreate($config, $service, $user, $externalId, $uuid);
        $response = $this->api->put('/api/admin/webspaces', $payload);
        if ($response->ok()) {
            $space = $this->unwrapWebspace($response->data());
            if ($space !== null) {
                return $this->normalise(self::TYPE_WEBSPACE, $space);
            }

            return $this->mustFind(self::TYPE_WEBSPACE, ['uuid' => $uuid, 'external_id' => $externalId]);
        }

        // Saved on the panel but the web node did not answer: retry the node sync once.
        if ($response->errorCode() === 'DAEMON_SYNC_FAILED') {
            $sync = $this->api->post('/api/admin/webspaces/' . $uuid . '/sync');
            if ($sync->ok()) {
                return $this->mustFind(self::TYPE_WEBSPACE, ['uuid' => $uuid, 'external_id' => $externalId]);
            }
            throw new FeatherpanelProvisionException(
                'The WebSpace was saved on the panel but its web node did not accept it: ' . $response->errorMessage()
                . '. It will be picked up again on the next attempt.'
            );
        }

        $existing = $this->find(self::TYPE_WEBSPACE, ['uuid' => $uuid, 'external_id' => $externalId]);
        if ($existing !== null) {
            return $existing;
        }

        throw new FeatherpanelProvisionException('The panel refused the WebSpace: ' . $response->errorMessage());
    }

    private function mustFind($type, array $ref)
    {
        $found = $this->find($type, $ref);
        if ($found === null) {
            throw new FeatherpanelProvisionException('The panel accepted the request but the new ' . $type . ' could not be read back.');
        }

        return $found;
    }

    // ---------------------------------------------------------------- lifecycle

    public function suspend($type, array $ref, $reason = null)
    {
        $res = $this->requireResource($type, $ref);
        if (!empty($res['suspended'])) {
            return $res;
        }
        $reason = trim((string) $reason);
        $body = ['reason' => strlen($reason) >= 3 ? $reason : 'Suspended by billing system'];
        $this->expectOk($this->api->post($this->resourcePath($type, $res) . '/suspend', $body), 'suspend');

        return $res;
    }

    public function unsuspend($type, array $ref)
    {
        $res = $this->requireResource($type, $ref);
        $this->expectOk($this->api->post($this->resourcePath($type, $res) . '/unsuspend'), 'unsuspend');

        return $res;
    }

    /**
     * Deletes the panel resource. Missing resources count as already deleted.
     *
     * @return bool True when something was deleted
     */
    public function terminate($type, array $ref)
    {
        $res = $this->find($type, $ref);
        if ($res === null) {
            return false;
        }
        if (!empty($res['pending'])) {
            throw new FeatherpanelProvisionException('The VDS is still being created; try again in a few minutes.');
        }
        $response = $this->api->delete($this->resourcePath($type, $res));
        if (!$response->ok() && $response->status !== 404) {
            throw new FeatherpanelProvisionException('Could not delete the ' . $type . ': ' . $response->errorMessage());
        }

        return true;
    }

    /**
     * Applies new package limits to an existing resource.
     */
    public function changePackage($type, array $ref, array $config)
    {
        $res = $this->requireResource($type, $ref);
        switch ($type) {
            case self::TYPE_GAME:
                $this->expectOk(
                    $this->api->patch('/api/admin/servers/' . $res['id'], FeatherpanelPayloads::gameLimits($config)),
                    'update the server limits'
                );
                break;
            case self::TYPE_VDS:
                $this->expectOk(
                    $this->api->patch('/api/admin/vm-instances/' . $res['id'], FeatherpanelPayloads::vdsLimits($config)),
                    'update the VDS'
                );
                $disk = (int) ($config['disk'] ?? 0);
                if ($disk > 0 && $res['disk'] !== null && $disk > $res['disk']) {
                    $this->expectOk(
                        $this->api->post('/api/admin/vm-instances/' . $res['id'] . '/resize-disk', ['disk' => 'scsi0', 'size' => $disk . 'G']),
                        'grow the VDS disk'
                    );
                }
                break;
            case self::TYPE_WEBSPACE:
                $this->expectOk(
                    $this->api->patch('/api/admin/webspaces/' . $res['uuid'], FeatherpanelPayloads::webspaceLimits($config)),
                    'update the WebSpace limits'
                );
                break;
        }

        return $this->mustFind($type, $ref + ['id' => $res['id'] ?? null, 'uuid' => $res['uuid'] ?? null]);
    }

    /**
     * Pushes admin edits (name, hostname, domain, owner) to the panel.
     */
    public function update($type, array $ref, array $changes)
    {
        $res = $this->requireResource($type, $ref);
        $body = FeatherpanelPayloads::edit($type, $changes);
        if (!empty($body)) {
            $this->expectOk($this->api->patch($this->resourcePath($type, $res), $body), 'save the changes');
        }

        return $this->mustFind($type, $ref);
    }

    public function powerState($type, array $ref)
    {
        $res = $this->find($type, $ref);
        if ($res === null) {
            return null;
        }
        if (!empty($res['pending'])) {
            return $res + ['state' => 'creating'];
        }
        $state = null;
        if ($type === self::TYPE_VDS) {
            $r = $this->api->get('/api/admin/vm-instances/' . $res['id'] . '/status');
            $state = $r->ok() ? ($r->data()['status'] ?? ($r->data()['state'] ?? null)) : null;
        } elseif ($type === self::TYPE_GAME) {
            $r = $this->api->get('/api/admin/servers/' . $res['id'] . '/runtime');
            $state = $r->ok() ? ($r->data()['state'] ?? ($r->data()['runtime']['state'] ?? null)) : null;
        } else {
            $r = $this->api->get('/api/admin/webspaces/' . $res['uuid'] . '/status');
            $state = $r->ok() ? ($r->data()['state'] ?? null) : null;
        }
        $res['state'] = is_string($state) ? $state : ($res['status'] ?? null);

        return $res;
    }

    /**
     * Returns a one-time login link for the panel that lands on the resource page.
     */
    public function loginUrl($userUuid, $type, array $res, $panelUrl)
    {
        $response = $this->api->post('/api/admin/users/' . rawurlencode($userUuid) . '/sso-token', []);
        if (!$response->ok() || empty($response->data()['token'])) {
            throw new FeatherpanelProvisionException('Could not create a login link: ' . $response->errorMessage());
        }
        $redirect = '/dashboard';
        if ($type === self::TYPE_GAME && !empty($res['uuid_short'])) {
            $redirect = '/server/' . $res['uuid_short'];
        } elseif ($type === self::TYPE_VDS && !empty($res['id'])) {
            $redirect = '/vds/' . $res['id'];
        } elseif ($type === self::TYPE_WEBSPACE && !empty($res['uuid_short'])) {
            $redirect = '/webspace/' . $res['uuid_short'];
        }

        return rtrim($panelUrl, '/') . '/auth/login?' . http_build_query([
            'sso_token' => $response->data()['token'],
            'redirect' => $redirect,
        ]);
    }

    private function requireResource($type, array $ref)
    {
        $res = $this->find($type, $ref);
        if ($res === null) {
            throw new FeatherpanelProvisionException('The ' . $type . ' for this service no longer exists on the panel.');
        }
        if (!empty($res['pending'])) {
            throw new FeatherpanelProvisionException('The VDS is still being created; try again in a few minutes.');
        }

        return $res;
    }

    private function resourcePath($type, array $res)
    {
        switch ($type) {
            case self::TYPE_GAME:
                return '/api/admin/servers/' . (int) $res['id'];
            case self::TYPE_VDS:
                return '/api/admin/vm-instances/' . (int) $res['id'];
            default:
                return '/api/admin/webspaces/' . rawurlencode($res['uuid']);
        }
    }

    private function expectOk(FeatherpanelApiResponse $response, $what)
    {
        if (!$response->ok()) {
            throw new FeatherpanelProvisionException('Could not ' . $what . ': ' . $response->errorMessage());
        }

        return $response;
    }
}
