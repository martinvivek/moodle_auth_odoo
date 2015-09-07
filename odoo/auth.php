<?php

/**
 * Abbility to login to Moodle using Odoo's login and password
 *
 * @package    auth
 * @subpackage odoo
 * @copyright  Laboratorium EE, www.laboratorium.ee
 * @author     Ludwik Trammer ludwik.trammer@laboratorium.ee
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

require_once($CFG->libdir.'/authlib.php');
require_once('xmlrpc.php');


class auth_plugin_odoo extends auth_plugin_base {

    /**
     * Constructor.
     */
    function auth_plugin_odoo() {
        $this->authtype = 'odoo';
        $this->roleauth = 'auth_odoo';
        $this->errorlogtag = '[AUTH odoo] ';
        $this->config = get_config('auth/odoo');

        set_config('field_updatelocal_firstname', 'onlogin', 'auth/odoo');
        set_config('field_updatelocal_lastname', 'onlogin', 'auth/odoo');
        set_config('field_updatelocal_city', 'onlogin', 'auth/odoo');
        set_config('field_updatelocal_email', 'onlogin', 'auth/odoo');
        set_config('field_updatelocal_country', 'onlogin', 'auth/odoo');
        set_config('field_updatelocal_institution', 'onlogin', 'auth/odoo');
    }

    /**
     * Performs an Odoo "read" query.
     *
     * @param string $model The model to query
     * @param array $ids An array of ids of objects to be retrived.
     * @param array $fields An array of names of fields to be retived from the objects.
     * @return bool An array of retrived objects.
     */
    function odoo_read($model, $ids, $fields) {
        $objs = xmlrpc_request(
            $this->config->url . '/xmlrpc/2/object',
            'execute_kw',
            array(
                $this->config->db,
                1, // superuser id in Odoo
                $this->config->password,
                $model,
                'read',
                array($ids),
                array(
                    'fields' => $fields
                )
            )
        );
        return $objs;
    }

    /**
     * Authenticates user against the selected authentication provide (Google, Facebook...)
     *
     * @param string $username The username (with system magic quotes)
     * @param string $password The password (with system magic quotes)
     * @return bool Authentication success or failure.
     */
    function user_login($username, $password) {
        $user_id = xmlrpc_request(
            $this->config->url . '/xmlrpc/2/common',
            'authenticate',
            array(
                $this->config->db,
                $username,
                $password,
                array()
            )
        );
        return $user_id && is_numeric($user_id);
    }

    /**
     * Returns true if this authentication plugin is 'internal'.
     *
     * @return bool
     */
    function is_internal() {
        return false;
    }

    function get_userinfo($username) {
        $userinfo = array();

        /* Get user id */
        $user_ids = xmlrpc_request(
            $this->config->url . '/xmlrpc/2/object',
            'execute_kw',
            array(
                $this->config->db,
                1, // superuser id in Odoo
                $this->config->password,
                'res.users',
                'search',
                array(
                    array(array('login', '=', $username)),
                ),
            )
        );
        /* Get user info */
        if($user_ids) {
            $users = $this->odoo_read(
                'res.users',
                $user_ids,
                array(
                    'name',
                    'email',
                    'city',
                    'city_gov',
                    'country',
                    'country_gov',
                    'coordinated_org',
                    'managed_projects',
                )
            );
            $user = $users[0];
            /* Basic fields */
            $name = explode(' ', $user['name'], 2);
            $userinfo['firstname'] = isset($name[0]) ? $name[0] : "";
            $userinfo['lastname'] = isset($name[1]) ? $name[1] : "";
            $userinfo['email'] = $user['email'];

            /* Non-standard fields */
            if(isset($user['city']) && $user['city']) {
                $userinfo['city'] = $user['city'];
            } elseif(isset($user['city_gov']) && $user['city_gov']) {
                $userinfo['city'] = $user['city_gov'];
            }

            /* get country code */
            $country_id = null;
            if(isset($user['country']) && $user['country']) {
                $country_id = $user['country'][0];
            } elseif(isset($user['country_gov']) && $user['country_gov']) {
                $country_id = $user['country_gov'][0];
            }
            if($country_id) {
                $countries = $this->odoo_read(
                    'res.country',
                    array($country_id),
                    array('code')
                );
                $userinfo['country'] = strtoupper($countries[0]['code']);
            }

            /* Get organizations */
            $organizations = array();
            if(isset($user['coordinated_org']) && $user['coordinated_org']) {
                $coordinated_orgs = $this->odoo_read(
                    'organization',
                    $user['coordinated_org'],
                    array('name')
                );
                $organizations[] = $coordinated_orgs[0]['name'];
            }
            if(isset($user['managed_projects']) && $user['managed_projects']) {
                // Get organizations from user's projects
                $projects = $this->odoo_read(
                    'bestja.project',
                    $user['managed_projects'],
                    array('organization')
                );
                foreach($projects as $project) {
                    $organizations[] = $project['organization'][1];
                }
                $organizations = array_unique($organizations);
            }
            $userinfo['institution'] = implode(', ', $organizations);
        }
        return $userinfo;
    }


    function config_form($config, $err, $user_fields) {
        global $OUTPUT;

        include "config.html";
    }

    /**
     * Processes and stores configuration data for this authentication plugin.
     */
    function process_config($config) {
        // set to defaults if undefined
        if (!isset ($config->db)) {
            $config->db = '';
        }
        if (!isset ($config->url)) {
            $config->url = '';
        }
        if (!isset ($config->password)) {
            $config->password = '';
        }

        // save settings 
        set_config('db',       $config->db,       'auth/odoo');
        set_config('url',      $config->url,      'auth/odoo');
        set_config('password', $config->password, 'auth/odoo');

        return true;
    }
}
