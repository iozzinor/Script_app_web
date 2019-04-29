<?php
    require_once(Configuration::get('root_path') . "Utils/model.php");

    require_once 'mail_manager.php';

    class User extends Model
    {
        private $mail_manager_;

        public function __construct()
        {
            parent::__construct('user');

            $this->mail_manager_ = new MailManager();
        }

        // ---------------------------------------------------------------------
        // CREATE
        // ---------------------------------------------------------------------
        /**
         * @return string The acccount activation link.
         */
        public function create(string $username, string $password, string $mail_address)
        {
            if ($this->is_username_in_use_($username))
            {
                throw new Exception('Username "' . $username . '" is already taken.');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $this->execute_request(
                'INSERT INTO user (username, password_hash, mail_address) VALUES (:username, :password_hash, :mail_address)',
                array(
                    ':username'         => $username,
                    ':password_hash'    => $hash,
                    ':mail_address'     => $mail_address
                )
            );

            return $this->create_account_activation_($username, $password, $mail_address);
        }

        /**
         * @return bool Whether the username is already in use.
         */
        private function is_username_in_use_(string $username)
        {
            $sql_result = $this->execute_request('SELECT count(id) AS users_count FROM user WHERE username=:username',
                array(':username' => $username));
            if ($result = $sql_result->fetchArray(SQLITE3_ASSOC))
            {
                $users_count = $result['users_count'];

                return $users_count > 0;
            }
            return false;
        }

        /**
         * @return string The acccount activation link.
         */
        private function create_account_activation_($username, $password, $mail_address)
        {
            $user_id = $this->authenticate_user($username, $password);

            $new_activation_passphrase = random_bytes(Configuration::get('account_passphrase_bytes_count'));
            $new_activation_passphrase = bin2hex($new_activation_passphrase);

            if (Configuration::get('send_activation_mail'))
            {
                $activated = false;
            }
            else
            {
                $activated = true;
            }

            $this->execute_request('INSERT INTO account_activation (user_id, activation_passphrase, activated) VALUES (:user_id, :activation_passphrase, :activated)',
                array(
                    ':user_id' => $user_id,
                    ':activation_passphrase' => $new_activation_passphrase,
                    ':activated' => ($activated ? 1 : 0)
                ));

            $account_activation_link = Configuration::get_base_url() . 'api/' . Configuration::get('current_api_version') . '/user/activate?activation_passphrase=' . $new_activation_passphrase;

            $activate_account_message = 'Please activate your account by clicking on the following link :';
            $activate_account_message .= $account_activation_link;

            if (!$activated)
            {
                $this->mail_manager_->send_mail($mail_address, 'Script App Account Activation', $activate_account_message, '');
                return $account_activation_link;
            }

            return '';
        }

        // ---------------------------------------------------------------------
        // ACTIVATE
        // ---------------------------------------------------------------------
        /**
         * @return bool Whether the account has been activated.
         */
        public function activate(string $activation_passphrase)
        {
            $sql_result = $this->execute_request('SELECT * FROM account_activation WHERE activation_passphrase=:activation_passphrase',
            array(':activation_passphrase' => $activation_passphrase));

            if (!$result = $sql_result->fetchArray(SQLITE3_ASSOC))
            {
                return false;
            }

            // already activated
            if ($result['activated'])
            {
                return false;
            }

            // proceed to the activation
            $this->execute_request('UPDATE account_activation SET activated=1 WHERE activation_passphrase=:activation_passphrase',
                array(':activation_passphrase' => $activation_passphrase));

            return true;
        }

        // ---------------------------------------------------------------------
        // USERS
        // ---------------------------------------------------------------------
        /**
         * @return array The list of all users.
         */
        public function get_all_users()
        {
            $sql_result = $this->execute_request('SELECT * FROM USER');
            $result = array();
            while ($current_result = $sql_result->fetchArray(SQLITE3_ASSOC))
            {
                array_push($result, $current_result);
            }
            return $result;
        }

        // ---------------------------------------------------------------------
        // CREDENTIALS
        // ---------------------------------------------------------------------
        /**
         * @return array The credentials or null.
         */
        public function check_credentials($key)
        {
            $now = time();
            $sql_result = $this->execute_request('SELECT expiration_date, key, user_id FROM user_credentials WHERE key=:key AND expiration_date > :now',
            array(':key' => $key, ':now' => $now));

            while ($result = $sql_result->fetchArray(SQLITE3_ASSOC))
            {
                return $result;
            }

            return null;
        }

        /**
         * @return int The user id or -1 if the user can not be authenticated.
         */
        public function authenticate_user($username, $password)
        {
            $sql_result = $this->execute_request('SELECT id, password_hash FROM user WHERE username=:username',
                array(':username' => $username));

            while ($result = $sql_result->fetchArray(SQLITE3_ASSOC))
            {
                $user_id = $result['id'];
                if (password_verify($password, $result['password_hash']))
                {
                    return $user_id;
                }
            }
            return -1;
        }

        /**
         * Throw an error in case of problem.
         * Store the new credentials in the database.
         * @return array The new credentials or null.
         */
        public function add_credentials($user_id)
        {
            $sql_result = $this->execute_request('SELECT count(id) AS credentials_count FROM user_credentials WHERE user_id=:user_id',
                array(':user_id' => $user_id));
            while ($result = $sql_result->fetchArray(SQLITE3_ASSOC))
            {
                $current_credentials_count = $result['credentials_count'];

                if ($current_credentials_count > Configuration::get('max_credentials_per_user'))
                {
                    throw new Exception('Too much credentials for this user.');
                }
                else
                {
                    $new_credentials_key = random_bytes(Configuration::get('credentials_bytes_count'));
                    $new_credentials_key = bin2hex($new_credentials_key);

                    $new_credentials_expiration_date = time() + Configuration::get('credentials_default_duration');

                    $this->execute_request('INSERT INTO user_credentials (user_id, expiration_date, key) VALUES(:user_id, :expiration_date, :key)',
                        array(
                            ':user_id'          => $user_id,
                            ':expiration_date'  => $new_credentials_expiration_date,
                            ':key'              => $new_credentials_key
                        ));
                    
                    return array(
                        'key' => $new_credentials_key,
                        'expiration_date' => $new_credentials_expiration_date,
                        'user_id' => $user_id
                    );
                }
            }
            return null;
        }

        /**
         * @return array The credentials associated to the user.
         */
        public function get_all_credentials($user_id)
        {
            $sql_result = $this->execute_request('SELECT user_id, expiration_date, key FROM user_credentials WHERE user_id=:user_id',
                array(':user_id' => $user_id));
            $result = array();
            
            while($current_result = $sql_result->fetchArray(SQLITE3_ASSOC))
            {
                array_push($result, $current_result);
            }
            return $result;
        }

        public function is_activated($user_id)
        {
            $sql_result = $this->execute_request('SELECT activated FROM account_activation WHERE user_id=:id', array(':id' => $user_id));
            if ($result = $sql_result->fetchArray(SQLITE3_ASSOC))
            {
                return $result['activated'] == 1;
            }
            return false;
        }

        /**
         * @return array The credentials that have been revoked.
         */
        public function revoke_credentials($credentials_key)
        {
            // obtain the credentials
            $credentials = $this->check_credentials($credentials_key);
            if ($credentials == null)
            {
                return null;
            }

            $this->execute_request('DELETE FROM user_credentials WHERE key=:key',
                array(':key' => $credentials_key)
            );
            return $credentials;
        }

        /**
         * @return array The credentials associated to the user, that have been revoked.
         */
        public function revoke_all_credentials($user_id)
        {
            $revoked_credentials = $this->get_all_credentials($user_id);

            $this->execute_request('DELETE FROM user_credentials WHERE user_id=:user_id',
                array(':user_id' => $user_id));

            return $revoked_credentials;
        }

        // ---------------------------------------------------------------------
        // DELETE
        // ---------------------------------------------------------------------
        public function delete($user_id)
        {
            $user_information = array();
            $sql_request = $this->execute_request('SELECT username from USER WHERE id=:id', array(':id' => $user_id));
            if ($result = $sql_request->fetchArray(SQLITE3_ASSOC))
            {
                $user_information = $result;
                $user_information['id'] = $user_id;
            }

            $user_id_array = array(':id' => $user_id);

            $this->execute_request('DELETE FROM user WHERE id=:id', $user_id_array);
            $this->execute_request('DELETE FROM account_activation WHERE user_id=:id', $user_id_array);
            $this->execute_request('DELETE FROM user_credentials WHERE user_id=:id', $user_id_array);

            return $user_information;
        }
    }
?>