<?php

namespace App\Services\Virtfusion;

use Illuminate\Support\Facades\Http;
use App\Services\ServiceInterface;
use App\Models\Package;
use App\Models\Order;

class Service implements ServiceInterface
{
    /**
     * Unique key used to store settings 
     * for this service.
     * 
     * @return string
     */
    public static $key = 'virtfusion'; 

    public function __construct(Order $order)
    {
        $this->order = $order;
    }
    
    /**
     * Returns the meta data about this Server/Service
     *
     * @return object
     */
    public static function metaData(): object
    {
        return (object)
        [
          'display_name' => 'VirtFusion',
          'author' => 'WemX',
          'version' => '1.0.0',
          'wemx_version' => ['dev', '>=1.8.0'],
        ];
    }

    /**
     * Define the default configuration values required to setup this service
     * i.e host, api key, or other values. Use Laravel validation rules for
     *
     * Laravel validation rules: https://laravel.com/docs/10.x/validation
     *
     * @return array
     */
    public static function setConfig(): array
    {
        // Check if the URL ends with a slash
        $doesNotEndWithSlash = function ($attribute, $value, $fail) {
            if (preg_match('/\/$/', $value)) {
                return $fail('AMP Panel URL must not end with a slash "/". It should be like https://panel.example.com');
            }
        };

        return [
            [
                "key" => "virtfusion::host",
                "name" => "Host",
                "description" => "The host / url of the VirtFusion Panel i.e https://panel.example.com",
                "type" => "url",
                "rules" => ['required', 'active_url', $doesNotEndWithSlash], // laravel validation rules
            ],
            [
                "key" => "encrypted::virtfusion::api_key",
                "name" => "API Key",
                "description" => "The API Key of the VirtFusion Panel",
                "type" => "password",
                "rules" => ['required'], // laravel validation rules
            ],
        ];
    }

    /**
     * Define the default package configuration values required when creatig
     * new packages. i.e maximum ram usage, allowed databases and backups etc.
     *
     * Laravel validation rules: https://laravel.com/docs/10.x/validation
     *
     * @return array
     */
    public static function setPackageConfig(Package $package): array
    {
        // collect the data then map with keus
        $collectPackages = collect(Service::api('get', '/packages')['data']);
        $packages = $collectPackages->mapWithKeys(function ($item) {
            if(!$item['enabled']) {
                return [];
            }

            return [$item['id'] => $item['name']];
        });

        return [
            [
                "col" => "col-12",
                "key" => "package",
                "name" => "Package",
                "description" => "Select the package to use for this service",
                "type" => "select",
                "options" => $packages->toArray(),
                "save_on_change" => true,
                "rules" => ['required'],
            ],
            [
                "col" => "col-12",
                "key" => "hypervisor_group_id",
                "name" => "Hyporvisor Group ID",
                "description" => "Enter the Hyporvisor Group ID to use for this service",
                "type" => "number",
                "save_on_change" => true,
                "rules" => ['required', 'numeric'],
            ],
            [
                "key" => "allowed_ips",
                "name" => "Number of Allowed ipv4 IPs",
                "description" => "Enter the number of allowed ipv4 IPs for this service",
                "type" => "number",
                "rules" => ['required', 'numeric'],
            ],
            [
                "key" => "storage",
                "name" => "Storage Limit (GB)",
                "description" => "Enter the storage limit for this service in GB",
                "default_value" => 20,
                "type" => "number",
                "rules" => ['required', 'numeric'],
            ],
            [
                "key" => "memory",
                "name" => "Memory Limit (MB)",
                "description" => "Enter the memory limit for this service in MB",
                "default_value" => 1024,
                "type" => "number",
                "rules" => ['required', 'numeric'],
            ],
            [
                "key" => "cpu_cores",
                "name" => "CPU Cores",
                "description" => "Enter the number of CPU Cores for this service",
                "default_value" => 5,
                "type" => "number",
                "rules" => ['required', 'numeric'],
            ],
        ];
    }

    /**
     * Define the checkout config that is required at checkout and is fillable by
     * the client. Its important to properly sanatize all inputted data with rules
     *
     * Laravel validation rules: https://laravel.com/docs/10.x/validation
     *
     * @return array
     */
    public static function setCheckoutConfig(Package $package): array
    {
        return [];
    }

    /**
     * Define buttons shown at order management page
     *
     * @return array
     */
    public static function setServiceButtons(Order $order): array
    {
        return [];    
    }

    /**
     * Test API connection
    */
    public static function testConnection()
    {
        try {
            // try to get list of packages through API request
            $response = Service::api('get', '/connect');
        } catch(\Exception $error) {
            // if try-catch fails, return the error with details
            return redirect()->back()->withError("Failed to connect to VirtFusion. <br><br>{$error->getMessage()}");
        }

        // if no errors are logged, return a success message
        return redirect()->back()->withSuccess("Successfully connected with VirtFusion");
    }

    /**
     * Init connection with API
    */
    public static function api($method, $endpoint, $data = [])
    {
        // make the request
        $url = settings('virtfusion::host') . '/api/v1' . $endpoint;
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . settings('encrypted::virtfusion::api_key'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->$method($url, $data);

        // dd($response, $response->json());

        if($response->failed())
        {
            // dd($response, $response->json());
            if(isset($response['errors'])) {
                throw new \Exception("[VirtFusion] " . json_encode($response['errors']));
            }

            if(isset($response['message'])) {
                throw new \Exception("[VirtFusion] " . $response['message']);
            }

            if($response->unauthorized() OR $response->forbidden()) {
                throw new \Exception("[VirtFusion] This action is unauthorized! Confirm that API token has the right permissions");
            }

            if($response->serverError()) {
                throw new \Exception("[VirtFusion] Internal Server Error: {$response->status()}");
            }

            throw new \Exception("[VirtFusion] Failed to connect to the API. Ensure the API details and hostname are valid.");
        }

        return $response;
    }

    /**
     * This function is responsible for creating an instance of the
     * service. This can be anything such as a server, vps or any other instance.
     * 
     * @return void
     */
    public function create(array $data = [])
    {
        $order = $this->order;
        $user = $order->user;
        $package = $order->package;
        
        // check if external user exists
        if(!$order->hasExternalUser()) {
            try {
                $externalUser = Service::api('post', '/users', [
                    "name" => $user->first_name . ' ' . $user->last_name,
                    'email' => $user->email,
                    'extRelationId' => $user->id,
                    'sendMail' => true,
                ])['data'];

                $order->createExternalUser([
                    'external_id' => $externalUser['id'], // optional
                    'username' => $user->email,
                    'password' => $externalUser['password'],
                    'data' => $externalUser, // Additional data about the user as an array (optional)
                 ]);

                //  Email the user their password
                $user->email([
                    'subject' => 'Panel Account Created',
                    'content' => "Your account has been created on the vps panel. You can login using the following details: <br><br> Email: {$user->email} <br> Password: {$externalUser['password']}",
                    'button' => [
                        'name' => 'VPS Panel',
                        'url' => settings('virtfusion::host'),
                    ]
                ]);
            } catch (\Exception $e) {
                throw new \Exception("Failed to create user on the panel, please make sure the email isn't already in use or that your name is longer than 10 chars");
            }
        }

        // create the server
        $response = Service::api('post', '/servers', [
            "packageId" => $package->data('package'),
            "userId" => $order->getExternalUser()->external_id,
            "hypervisorId" => $package->data('hypervisor_group_id'),
            "ipv4" => $package->data('allowed_ips', 1),
            "storage" => $package->data('storage', 20),
            "memory" => $package->data('memory', 1024),
            "cpuCores" => $package->data('cpu_cores', 5),
        ]);

        $order->external_id = $response['data']['id'];
        $order->data = $response['data'];
        $order->save();

        return $response;
    }

    /**
     * This function is responsible automatically logging in to the
     * panel when the user clicks the login button in the client area.
     * 
     * @return redirect
    */
    public function loginToPanel(Order $order)
    {
        try {
            $response = Service::api('post', "/users/{$order->user->id}/serverAuthenticationTokens/{$order->external_id}");
            return redirect(settings('virtfusion::host') . $response['data']['authentication']['endpoint_complete']);
        } catch (\Exception $e) {
            return redirect()->back()->withError("Something went wrong, please try again later.");
        }
    }

    /**
     * This function is responsible for upgrading or downgrading
     * an instance of this service. This method is optional
     * If your service doesn't support upgrading, remove this method.
     * 
     * Optional
     * @return void
    */
    // public function upgrade(Package $oldPackage, Package $newPackage)
    // {
    //     return [];
    // }

    /**
     * This function is responsible for suspending an instance of the
     * service. This method is called when a order is expired or
     * suspended by an admin
     * 
     * @return void
    */
    public function suspend(array $data = [])
    {
        $order = $this->order;
        Service::api('post', '/servers/' . $order->external_id . '/suspend');
    }

    /**
     * This function is responsible for unsuspending an instance of the
     * service. This method is called when a order is activated or
     * unsuspended by an admin
     * 
     * @return void
    */
    public function unsuspend(array $data = [])
    {
        $order = $this->order;
        Service::api('post', '/servers/' . $order->external_id . '/unsuspend');
    }

    /**
     * This function is responsible for deleting an instance of the
     * service. This can be anything such as a server, vps or any other instance.
     * 
     * @return void
    */
    public function terminate(array $data = [])
    {
        $order = $this->order;
        Service::api('delete', '/servers/' . $order->external_id . '?delay=5');
    }

}
