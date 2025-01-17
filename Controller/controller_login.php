<?php
    require_once (Router::get_base_path() . '/Model/user.php');

    class ControllerLogin extends Controller
    {
        private $additional_resources_;
        private $additional_scripts_;
        private $user_;

        public function __construct(Request $request, ControllerInformation $information)
        {
            parent::__construct($request, $information);

            $this->user_ = new User();

            // additional resources
            $this->additional_resources_ = array();
            array_push($this->additional_resources_, array('rel' => 'stylesheet', 'href' => '/Content/Styles/hoverable_button.css'));
            array_push($this->additional_resources_, array('rel' => 'stylesheet', 'href' => '/Content/Styles/login.css'));

            // additional scripts
            $this->additional_scripts_ = array();
            array_push($this->additional_scripts_, array('src' => '/Content/Scripts/Dialog/dialog.js'));
            array_push($this->additional_scripts_, array('src' => '/Content/Scripts/hoverable_button.js'));
            array_push($this->additional_scripts_, array('src' => '/Content/Scripts/disable_button.js'));
            array_push($this->additional_scripts_, array('src' => '/Content/Scripts/login.js'));
        }

        // ---------------------------------------------------------------------
        // DEFAULT ACTION
        // ---------------------------------------------------------------------
        public function default_action()
        {
            // check that the user is not already logged in
            if (Login::is_logged_in())
            {
                header('Location: home');
                exit;
            }

            if (!isset($_SESSION['attempts']))
            {
                $_SESSION['attempts'] = 0;
            }
            $attempts = $_SESSION['attempts'];
            $_SESSION['redirection_path'] = $this->get_redirection_path_();

            $view_information = array(
                'title'                 => _d('login', 'title'),
                'navigation_menus'      => ControllerSecure::get_navigation_menus(),
                'username'              => '',
                'password'              => '',
                'attempts'              => $attempts,
                'additional_resources'  => $this->additional_resources_,
                'additional_scripts'    => $this->additional_scripts_
            );

            $this->generate_view($view_information, 'template.php');
        }

        /**
         * Attempt to get the redirection URL, i.e. the URL the user tried
         * to access when not authenticated.
         * 
         * Return home by default.
         * 
         * @return string The redirection URL.
         */
        private function get_redirection_path_()
        {
            if ($this->request_->parameter_exists('try_to_forward_to'))
            {
                return $this->request_->get_parameter('try_to_forward_to');
            }
            return 'home';
        }

        /**
         * Load the user preferences :
         * - set the language
         */
        private function load_preferences_($user_id)
        {
            $preferences = $this->user_->load_preferences($user_id);
            if ($preferences != null)
            {
                $_SESSION['lang'] = $preferences->get_language_short_name();
            }
            return $preferences;
        }

        public function perform()
        {
            // update the attempt number
            if (!isset($_SESSION['attempts']))
            {
                $_SESSION['attempts'] = 0;
            }
            $attempts = $_SESSION['attempts'];

            if (!($this->request_->parameter_exists('username') && $this->request_->parameter_exists('password')))
            {
                http_response_code(400); // bad request
                exit;
            }

            $username = $this->request_->get_parameter('username');
            $password = $this->request_->get_parameter('password');

            $user_id = $this->user_->authenticate_user($username, $password);

            if ($user_id < 1)
            {
                sleep(1);
                
                $attempts += 1;
                $_SESSION['attempts'] += 1;

                http_response_code(401); // unauthorized
                $wrong_attemps_message = sprintf(_dn('login', 'One wrong attempt.', '%d wrong attempts.', $attempts), $attempts);
                print($wrong_attemps_message);
            }
            else
            {
                $_SESSION['username']   = $username;
                $_SESSION['user_id']    = $user_id;

                if (!$this->user_->is_account_activated($user_id))
                {
                    print(Router::get_base_url() . 'account_not_activated');
                }
                else
                {
                    // load preferences
                    $privileges = $this->user_->load_privileges($user_id);
                    $preferences = $this->load_preferences_($user_id);
                    $_SESSION['user_preferences']       = serialize($preferences);
                    $_SESSION['user_privileges']        = serialize($privileges);
                    $_SESSION['user_privileges_mask']   = Login::get_rank_mask($privileges);
    
                    if ($this->user_->should_display_privilege_upgrade($user_id))
                    {
                        print(Router::get_raw_base_url() . $preferences->get_language_short_name() . '/privileges/upgrade');
                    }
                    else
                    {
                        print(Router::get_raw_base_url() . $preferences->get_language_short_name() . '/' . $_SESSION['redirection_path']);
                    }
                }

                unset($_SESSION['redirection_path']);
                http_response_code(200);
            }
        }
    }
?>