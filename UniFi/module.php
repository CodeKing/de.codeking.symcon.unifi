<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/helpers/autoload.php');
require_once(__ROOT__ . '/libs/UniFi-API-browser/vendor/autoload.php');

/**
 * Class Unifi
 * IP-Symcon Unifi Module
 *
 * @version     1.1
 * @category    Symcon
 * @package     de.codeking.symcon.unifi
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKing/de.codeking.symcon.unifi
 *
 */
class Unifi extends Module
{
    use InstanceHelper;

    private $user;
    private $password;
    private $url;

    public $data = [];
    public $devices = [];
    public $presence_online_time;

    private $data_mapper = [
        'wan_ip' => 'IP',
        'xput_down' => 'Download',
        'xput_up' => 'Upload',
        'num_guest' => 'Guests Online',
        'latency' => 'Latency'
    ];

    protected $profile_mappings = [
        'Latency' => 'Latency',
        'Upload' => 'MBit.Upload',
        'Download' => 'MBit.Download',
        'WiFi: Guests' => '~Switch'
    ];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register public properties
        $this->RegisterPropertyString('user', '');
        $this->RegisterPropertyString('password', '');
        $this->RegisterPropertyString('url', 'https://192.168.1.10:8443');

        // register presence properties
        $this->RegisterPropertyInteger('presence_online_time', 15);

        $this->RegisterPropertyString('devices', '[]');

        // register data timer every 10 minutes
        $register_timer = 60 * 10 * 100;
        $this->RegisterTimer('UpdateData', $register_timer, $this->_getPrefix() . '_Update($_IPS[\'TARGET\']);');

        // register presence timer every minute
        $this->RegisterTimer('UpdatePresence', 30000, $this->_getPrefix() . '_UpdatePresence($_IPS[\'TARGET\']);');

        // create and enable guest portal switch
        $this->CreateVariableByIdentifier([
            'parent_id' => $this->InstanceID,
            'name' => 'WiFi: Guests',
            'value' => false,
            'position' => 99,
            'identifier' => 'guest_portal'
        ]);
        $this->EnableAction('guest_portal');
    }

    /**
     * execute, when kernel is ready
     */
    protected function onKernelReady()
    {
        // read config
        $this->readConfig();

        // update data
        if ($this->user && $this->password && $this->url) {
            $this->Update();
            $this->UpdatePresence();
        }
    }

    /**
     * Request Actions
     * @param string $Ident
     * @param $Value
     * @return bool|array
     */
    public function RequestAction($Ident, $Value)
    {
        if ($Ident == $this->identifier('guest_portal')) {
            if ($settings = $this->Api('list_wlanconf')) {
                foreach ($settings AS $config) {
                    if (isset($config->is_guest) && $config->is_guest == true) {
                        $this->Api('disable_wlan', $config->_id, !$Value);
                        SetValue($this->GetIDForIdent($Ident), $Value);
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Read config
     */
    private function ReadConfig()
    {
        // controller config
        $this->url = $this->ReadPropertyString('url');
        $this->user = $this->ReadPropertyString('user');
        $this->password = $this->ReadPropertyString('password');

        // presence config
        $this->presence_online_time = $this->ReadPropertyInteger('presence_online_time');

        // parse device config
        $devices = json_decode($this->ReadPropertyString('devices'), true);
        if (is_array($devices)) {
            foreach ($devices AS $device) {
                $this->devices[$device['mac']] = [
                    'name' => $device['name'],
                    'is_online' => false
                ];
            }
        }
    }

    /**
     * read & update unifi data
     */
    public function Update()
    {
        // read config
        $this->readConfig();

        // get health data
        $health_data = $this->Api('list_health');

        // extract useful data
        foreach ($health_data AS $data) {
            foreach ($data AS $key => $value) {
                if (isset($this->data_mapper[$key])) {
                    $key = $this->data_mapper[$key];
                    $this->data[$key] = $value;
                }
            }
        }

        // update guest wifi switch
        if ($settings = $this->Api('list_wlanconf')) {
            foreach ($settings AS $config) {
                if (isset($config->is_guest) && $config->is_guest == true) {
                    SetValue($this->GetIDForIdent($this->identifier('guest_portal')), ($config->enabled));
                    break;
                }
            }
        }

        // log data
        $this->_log('UniFi Data', json_encode($this->data));

        // save data
        $this->SaveData();
    }

    /**
     * save tank data to variables
     */
    private function SaveData()
    {
        // loop unifi data and add variables
        $position = 0;
        foreach ($this->data AS $key => $value) {
            $this->CreateVariableByIdentifier([
                'parent_id' => $this->InstanceID,
                'name' => $key,
                'value' => $value,
                'position' => $position
            ]);
            $position++;
        }
    }

    /**
     * device presence detection
     */
    public function UpdatePresence()
    {
        // read config
        $this->readConfig();

        // get clients
        $clients = $this->Api('list_clients');

        // loop clients and check device presence
        foreach ($clients AS $client) {
            $mac_address = strtolower($client->mac);
            if (isset($this->devices[$mac_address]) && $this->is_device_online($client->last_seen)) {
                $this->devices[$mac_address]['is_online'] = true;
            }
        }

        // log data
        $this->_log('UniFi Presence Data', json_encode($this->devices));

        // save data
        $this->SavePresenceData();
    }

    /**
     * Save Presence Data
     */
    private function SavePresenceData()
    {
        // create folder 'Presence'
        $category_id_presence = $this->CreateCategoryByIdentifier($this->InstanceID, 'Presences', 'Presences', 'Motion');

        // loop devices add variables
        $position = 0;
        foreach ($this->devices AS $mac_address => $device) {
            $this->profile_mappings[$device['name']] = 'Presence';
            $this->CreateVariableByIdentifier([
                'parent_id' => $category_id_presence,
                'name' => $device['name'],
                'value' => $device['is_online'],
                'position' => $position,
                'identifier' => $mac_address
            ]);
            $position++;
        }
    }

    /**
     * UniFi API
     * @param string $request
     * @return array
     */
    public function Api(string $request)
    {
        // read config
        $this->ReadConfig();

        // login to api
        $api = new UniFi_API\Client($this->user, $this->password, $this->url);
        $login_state = @$api->login();

        // login failed
        if ($login_state !== true) {
            $this->SetStatus(201);
            $this->_log('UniFi', 'Error: Could not connect to unifi controller! Please check your credentials!');
            exit(-1);
        }

        // valid request
        $this->SetStatus(102);

        // exec request
        if (func_num_args() > 1) {
            $arguments = func_get_args();
            unset($arguments[0]);

            return call_user_func_array([$api, $request], $arguments);

        } else {
            return $api->$request();
        }
    }

    /**
     * check if a presence timestamp - time diff is still 'online'
     * @param int $timestamp
     * @return bool
     */
    private function is_device_online(int $timestamp)
    {
        $diff = time() - $timestamp;
        return ($diff < $this->presence_online_time * 60);
    }

    /**
     * create custom variable profile
     * @param string $profile_id
     * @param string $name
     */
    protected function CreateCustomVariableProfile(string $profile_id, string $name)
    {
        switch ($name):
            case 'Latency':
                IPS_CreateVariableProfile($profile_id, 1); // integer
                IPS_SetVariableProfileText($profile_id, '', ' ms'); // milliseconds
                IPS_SetVariableProfileIcon($profile_id, 'Graph');
                break;
            case 'MBit.Upload':
                IPS_CreateVariableProfile($profile_id, 2); // float
                IPS_SetVariableProfileDigits($profile_id, 2); // 2 decimals
                IPS_SetVariableProfileText($profile_id, '', ' MBit'); // MBit
                IPS_SetVariableProfileIcon($profile_id, 'HollowArrowUp');
                break;
            case 'MBit.Download':
                IPS_CreateVariableProfile($profile_id, 2); // float
                IPS_SetVariableProfileDigits($profile_id, 2); // 2 decimals
                IPS_SetVariableProfileText($profile_id, '', ' MBit'); // MBit
                IPS_SetVariableProfileIcon($profile_id, 'HollowArrowDown');
                break;
            case 'Presence':
                IPS_CreateVariableProfile($profile_id, 0); // boolean
                IPS_SetVariableProfileAssociation($profile_id, 0, $this->Translate('absent'), '', -1);
                IPS_SetVariableProfileAssociation($profile_id, 1, $this->Translate('present'), '', -1);
                break;
        endswitch;
    }

}