<?php

class deniska_theme_msgraph{
    var $version = '1.0';

    /**
     * Class instance
     */
    static $instance;

    /**
     * @var array $creds
     */
    var $creds = array(
        'OAUTH_APP_ID'                  => 'YOUR_APP_ID_HERE'
        ,'OAUTH_APP_PASSWORD'           => 'YOUR_APP_PASSWORD_HERE'
        ,'OAUTH_REDIRECT_URI'           => 'YOUR_APP_CALLBACK_HERE'
        ,'OAUTH_SCOPES'                 => ''
        ,'OAUTH_AUTHORITY'              => 'https://login.microsoftonline.com'
        ,'OAUTH_AUTHORIZE_ENDPOINT'     => '/oauth2/v2.0/authorize'
        ,'OAUTH_TOKEN_ENDPOINT'         => '/oauth2/v2.0/token'
    );

    /**
     * @var array $access_token
     */
    var $access_token = array();

    var $session_type;

    var $session_key;

    /**
     * @var string $composer_path
     */
    static $composer_path = '';


    /**
     * Get class instance
     *
     * @since 1.0 introduced
     * @return binus the class instance
     */
    public static function get_instance(){
        if(self::$instance === null)
        {
            self::$composer_path = func_get_arg(0);

            self::$instance = new self();
        }
        
        require self::$composer_path . '/autoload.php';

        return self::$instance;
    }

    /**
     * Set engine credential
     */
    function set_credential(
        $app_id
        ,$app_secret
        ,$redirect_uri
        ,$tenant_id
        ,$scope = 'openid profile offline_access user.read'
    ){
        $this->creds['OAUTH_APP_ID']        = $app_id;
        $this->creds['OAUTH_APP_PASSWORD']  = $app_secret;
        $this->creds['OAUTH_REDIRECT_URI']  = $redirect_uri;
        $this->creds['OAUTH_SCOPES']        = $scope;
        $this->creds['OAUTH_AUTHORITY']     .= "/{$tenant_id}";
    }

    /**
     * Get Client Credentials
     * 
     * @return array 
     */
    private function get_client_creds(){
        return array(
            'clientId'                => $this->creds['OAUTH_APP_ID'],
            'clientSecret'            => $this->creds['OAUTH_APP_PASSWORD'],
            'redirectUri'             => $this->creds['OAUTH_REDIRECT_URI'],
            'urlAuthorize'            => $this->creds['OAUTH_AUTHORITY'] . $this->creds['OAUTH_AUTHORIZE_ENDPOINT'],
            'urlAccessToken'          => $this->creds['OAUTH_AUTHORITY'] . $this->creds['OAUTH_TOKEN_ENDPOINT'],
            'urlResourceOwnerDetails' => '',
            'scopes'                  => $this->creds['OAUTH_SCOPES']
        );
    }

    /**
     * Get MS Graph Login URL
     * 
     * @return string logged in URL
     */
    public function get_signin_url(){
        // Initialize the OAuth client
        $oauthClient = new \League\OAuth2\Client\Provider\GenericProvider($this->get_client_creds());

        return $oauthClient->getAuthorizationUrl();
    }

    /**
     * Validate 0auth request callback
     * 
     * @return array an array containing status, message and token when succeed
     */
    public function auth_validation(){
        // Authorization code should be in the "code" query param
        if(empty($_GET['code']))
        {
            return array(
                'status'    => false
                ,'message'  => 'No authentication code found'
            );
        }

        $authCode = $_GET['code'];

        $oauthClient = new \League\OAuth2\Client\Provider\GenericProvider($this->get_client_creds());

        try 
        {
            $accessToken = $oauthClient->getAccessToken('authorization_code', array( 'code' => $authCode ) );

            $this->set_access_token($accessToken);

            return array(
                'status'    => true
                ,'token'    => $accessToken
            );
        }
        catch (League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) 
        {
            return array(
                'status'    => false
                ,'message'  => $e->getMessage()
            );
        }
    }

    /**
     * Set engine active access token
     * 
     * @param array $token access token to store
     * @return void
     */
    public function set_access_token($token){
        
        $this->access_token = array(
            'access_token'      => $token->getToken()
            ,'refresh_token'    => $token->getRefreshToken()
            ,'expires'          => $token->getExpires()
        );

        $this->session_store();
    }

    /**
     * Tell engine to enable session for current user or no
     * 
     * @param string $session_type the storing session to use
     */
    public function enable_session($session_type){
        $this->session_type = $session_type;
        $this->session_key  = md5($this->creds['OAUTH_APP_ID']);
    }

    /**
     * Clear stored token
     * 
     * @return void
     */
    public function clear_token(){
        $this->access_token = array();
    }

    /**
     * Get stored token
     * 
     * @return string access token
     */
    public function get_access_token(){
        
        $stored_session = $this->session_retrieve();

        if(empty($this->access_token) AND $stored_session === false )
        {
            return '';
        }
        else if( empty($this->access_token) AND $stored_session !== false )
        {
            $this->access_token = $stored_session['token'];
        }

        $now = time() + 300;

        if ($this->access_token['expires'] <= $now) 
        {
            $oauthClient = new \League\OAuth2\Client\Provider\GenericProvider($this->get_client_creds());

            try {
                $token = $oauthClient->getAccessToken('refresh_token'
                    ,array( 
                        'refresh_token' => $this->access_token['refresh_token']
                    ) 
                );

            
                $this->set_access_token($token);

                return $token->getToken();
            }
            catch (League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) 
            {
                return '';
            }
        }

        return $this->access_token['access_token'];
    }

    /**
     * Get MS Graph Object
     */
    public function get_graph(){
        $graph          = new Microsoft\Graph\Graph();
        $accessToken    = $this->get_access_token();

        $graph->setAccessToken($accessToken);

        return $graph;
    }

    /**
     * Get current sign in user
     * 
     * @return array user array
     */
    public function get_logged_in_user(){
        return $this->get_graph()->createRequest('GET', '/me')
            ->setReturnType(Microsoft\Graph\Model\User::class)
            ->execute();
    }

    public function get_logged_in_user_email(){
        $graph_user = $this->get_logged_in_user();
        return null !== $graph_user->getMail() ? $graph_user->getMail() : $graph_user->getUserPrincipalName();
    }
    
    public function get_logged_in_user_photo(){
        $photo = $this->get_graph()->createRequest("GET", "/me/photo/\$value")->execute();
        $photo = $photo->getRawBody();
                                
        $meta = $this->get_graph()->createRequest("GET", "/me/photo")->execute();
        $meta = $meta->getBody();

        return 'data:'.$meta["@odata.mediaContentType"].';base64,'.base64_encode($photo);

    }

    private function session_store(){
        switch($this->session_type)
        {
            case 'session':
            case 'cookies':
                $email          = $this->get_logged_in_user_email();
                $email_token    = md5($email, 'deniska-graph');
                $stored = base64_encode(serialize(array(
                    'email'         => $email
                    ,'email_token'  => $email_token
                    ,'token'        => $this->access_token
                )));
                $_SESSION[$this->session_key] = $stored;
                break;
            default:
                break;
        }
    } 

    function session_retrieve(){
        switch($this->session_type)
        {
            case 'session':
            case 'cookies':
                $session = isset($_SESSION[$this->session_key]) ? $_SESSION[$this->session_key] : base64_encode(serialize(array()));
                $stored = unserialize(base64_decode($session));
                if( empty($stored['email']) AND empty($stored['email_token']) )
                {
                    return false;
                }

                if(md5($stored['email'], 'deniska-graph') != $stored['email_token'])
                {
                    return false;
                }

                return $stored;
            default:
                return false;
        }
    }

    function logout(){
        if(isset($_SESSION[$this->session_key]))
        {
            unset($_SESSION[$this->session_key]);
        }
    }
}