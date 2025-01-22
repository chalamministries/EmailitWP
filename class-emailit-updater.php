<?php
class EmailIt_Plugin_Updater {
    private $slug;
    private $pluginData;
    private $username;
    private $repo;
    private $pluginFile;
    private $githubAPIResult;
    private $accessToken;
    private $pluginActivated;

    /**
     * Class constructor.
     *
     * @param string $pluginFile
     * @param string $gitHubUsername
     * @param string $gitHubRepo
     * @param string $accessToken
     */
    function __construct($pluginFile, $gitHubUsername, $gitHubRepo, $accessToken = '') {
        $this->pluginFile = $pluginFile;
        $this->username = $gitHubUsername;
        $this->repo = $gitHubRepo;
        $this->accessToken = $accessToken;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'setTransitent'));
        add_filter('plugins_api', array($this, 'setPluginInfo'), 10, 3);
        add_filter('upgrader_pre_install', array($this, 'preInstall'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'postInstall'), 10, 3);
    }

    /**
     * Get information regarding our plugin from WordPress
     */
    private function initPluginData() {
        $this->slug = plugin_basename($this->pluginFile);
        $this->pluginData = get_plugin_data($this->pluginFile);
    }

    /**
     * Get information regarding our plugin from GitHub
     */
    private function getRepoReleaseInfo() {
        if (!empty($this->githubAPIResult)) {
            return;
        }

        $url = "https://api.github.com/repos/{$this->username}/{$this->repo}/releases";
        if ($this->accessToken) {
            $url = add_query_arg(array('access_token' => $this->accessToken), $url);
        }

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            return;
        }

        $releases = json_decode(wp_remote_retrieve_body($response));

        if (is_array($releases)) {
            // Get the latest release
            $this->githubAPIResult = $releases[0];
        }
    }

    /**
     * Push in plugin version information to get the update notification
     */
    public function setTransitent($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get plugin & GitHub release information
        $this->initPluginData();
        $this->getRepoReleaseInfo();

        $doUpdate = version_compare($this->githubAPIResult->tag_name, $transient->checked[$this->slug]);

        if ($doUpdate) {
            $package = $this->githubAPIResult->zipball_url;
            if ($this->accessToken) {
                $package = add_query_arg(array('access_token' => $this->accessToken), $package);
            }

            // Plugin object
            $obj = new stdClass();
            $obj->slug = $this->slug;
            $obj->new_version = $this->githubAPIResult->tag_name;
            $obj->url = $this->pluginData["PluginURI"];
            $obj->package = $package;

            $transient->response[$this->slug] = $obj;
        }

        return $transient;
    }

    /**
     * Push in plugin version information to display in the details lightbox
     */
    public function setPluginInfo($false, $action, $response) {
        // Get plugin & GitHub release information
        $this->initPluginData();
        $this->getRepoReleaseInfo();

        if (empty($response->slug) || $response->slug != $this->slug) {
            return $false;
        }

        // Add our plugin information
        $response->last_updated = $this->githubAPIResult->published_at;
        $response->slug = $this->slug;
        $response->name = $this->pluginData["Name"];
        $response->version = $this->githubAPIResult->tag_name;
        $response->author = $this->pluginData["AuthorName"];
        $response->homepage = $this->pluginData["PluginURI"];

        // Extract the changelog from GitHub release body
        $response->sections = array(
            'description' => $this->pluginData["Description"],
            'changelog' => nl2br($this->githubAPIResult->body)
        );

        // Gets the required version of WP if available
        $matches = null;
        preg_match("/requires:\s([\d\.]+)/i", $this->githubAPIResult->body, $matches);
        if (!empty($matches)) {
            if (is_array($matches)) {
                if (count($matches) > 1) {
                    $response->requires = $matches[1];
                }
            }
        }

        return $response;
    }

    /**
     * Perform check before installation starts
     */
    public function preInstall($true, $args) {
        // Get plugin information
        $this->initPluginData();

        // Check if the plugin was installed before...
        $this->pluginActivated = is_plugin_active($this->slug);

        return $true;
    }

    /**
     * Perform additional actions to successfully install our plugin
     */
    public function postInstall($true, $hook_extra, $result) {
        global $wp_filesystem;

        // Get plugin information
        $this->initPluginData();

        // Remember if our plugin was previously activated
        if ($this->pluginActivated) {
            // Since we are hosted in GitHub, our plugin folder would have a dirname of
            // reponame-tagname change it to our original one:
            $pluginFolder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname($this->slug);
            $wp_filesystem->move($result['destination'], $pluginFolder);
            $result['destination'] = $pluginFolder;

            // Re-activate plugin if needed
            if ($this->pluginActivated) {
                $activate = activate_plugin($this->slug);
            }
        }

        return $result;
    }
}
