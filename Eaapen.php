<?php
namespace eaapen;

use EasyFirestore;
use AutoTemplate\AutoTemplate;
use Exception;
use Google_Client;
use Google_Service_Oauth2;
use Google_Service_Directory;
use Google_Service_Directory_User;
use Google_Service_Exception;

class Eaapen
{
    public EasyFirestore $firestore;
    public AutoTemplate $template;
    private string $oauthClientIdFile;
    private string $finishLoginUrl;
    private string $finishAdminLoginUrl;
    private string $gappsDomain;
    // for cacheing to improve performance
    private ?array $adminAccessToken = null;
    
    public function __construct(
        string $title,
        array $menuItems = [],
        string $finishLoginUrl = '/login.php',
        string $finishAdminLoginUrl = '',
        string $gappsDomain = '',
        ?string $oauthClientIdFile = null
    ) {
        $this->finishLoginUrl = self::makeAbsolute($finishLoginUrl);
        $this->finishAdminLoginUrl = self::makeAbsolute($finishAdminLoginUrl);
        $this->gappsDomain = $gappsDomain;
        
        // create a new firestore instance and register it as the session
        // handler
        $this->firestore = new EasyFirestore();
        $this->firestore->startSession();
        
        // if a client-id file was given use it, else use the default path
        $oauthClientIdFile ??= dirname(__DIR__, 3) . '/oauth-client-id.json';
        $this->oauthClientIdFile = realpath($oauthClientIdFile);
        if (!file_exists($this->oauthClientIdFile)) {
            throw new Exception(
                "No OAuth Client ID file found at "
                . "'{$this->oauthClientIdFile}'. You will need to get one "
                . "from the Google Cloud Console."
            );
        }
        
        // start auto-template
        $this->template = new AutoTemplate($title, $menuItems);
        $this->template->registerShutdownFunc(function ($template) {
            // hide the log-in button if the user is already logged in and
            // the log out button if the user is already logged out.
            if ($this->isLoggedIn()) {
                unset($template->menuItems['Log In']);
            } else {
                unset($template->menuItems['Log Out']);
            }
        });
    }
    
    // returns an oauth client with the client-id file already set
    public function newOAuthClient(): Google_Client
    {
        $client = new Google_Client();
        $client->setAuthConfig($this->oauthClientIdFile);
        return $client;
    }
    
    // get an oauth client with the credentials of our admin user
    public function newAdminOAuthClient(): Google_Client
    {
        // try to pull cached access token. Fall back to kv store.
        $accessToken = $this->adminAccessToken ?? $this
            ->firestore
            ->kvRead('EAAPEN_googleAdminAccessToken');
        if (empty($accessToken)) {
            throw new Exception(
                'No admin credentials available'
            );
        }
        // cache access token to save time later
        $this->adminAccessToken = $accessToken;
        
        // create a separate google client using the scopes of our admin.
        $client = $this->newOAuthClient();
        $client->setAccessToken($accessToken);
        
        return $client;
    }
    
    // return a user object from an email
    // http://bit.do/google-user-object-php
    public function user(string $email): Google_Service_Directory_User
    {
        // get google admin service client
        $client = $this->newAdminOAuthClient();
        $googleAdmin = new Google_Service_Directory($client);
        
        return $googleAdmin->users->get($email);
    }
    
    // return the ou of the current user (ex: /Staff/TECH)
    public function userOU(string $email): string
    {
        return $this->user($email)->getOrgUnitPath();
    }
    
    // returns all OUs that a user is directly or indirectly a member of.
    // for a user in '/Staff/TECH', this would be
    // ['/', '/Staff', '/Staff/TECH']
    public function userOUs(string $email): array
    {
        // get OU elements ['', 'Staff', 'TECH']
        $mainOU = $this->userOU($email);
        $elements = explode('/', $mainOU);
        
        $ous = [];
        $ou = '';
        foreach ($elements as $element) {
            $ou = '/' . trim("$ou/$element", '/');
            $ous[] = $ou;
        }
        
        return $ous;
    }
    
    // return an array of strings representing the email addresses of all
    // groups a user is (directly or indirectly) a member of. This will not
    // include the user's own email address.
    private function userGroups(string $email): array
    {
        // create a separate google client using the scopes of our admin.
        // This lets us use the Admin Directory service.
        $client = $this->newAdminOAuthClient();
        $googleAdmin = new Google_Service_Directory($client);
        
        // list all groups a user is a member of
        // NOTE: google starts paginating results if you are a member of
        // more than 200 groups. That behavior here is untested.
        $groups = $googleAdmin->groups->listGroups([
            'userKey' => $email
        ])->getGroups();
        
        // pull just the email out of the groups
        $groupEmails = [];
        foreach ($groups as $group) {
            $groupEmail = strtolower($group->getEmail());
            $groupEmails[] = $groupEmail;
            
            // check if this group is a member of any other groups
            $indirectGroups = $this
                ->userGroups($groupEmail);
            $groupEmails = array_merge($groupEmails, $indirectGroups);
        }
        
        // don't return duplicate groups. This can happen if a user is
        // directly and indirectly a member of a group.
        return array_unique($groupEmails);
    }
    
    // return the base url of the server (without trailing slash)
    public static function serverUrl(): string
    {
        if (isset($_SERVER['HTTP_X_APPENGINE_DEFAULT_VERSION_HOSTNAME'])) {
            $host = $_SERVER['HTTP_X_APPENGINE_DEFAULT_VERSION_HOSTNAME'];
            return "https://$host";
        } else {
            // if we are not on app engine, use the less-secure method of
            // trusting the host header. This should be good enough for the
            // dev environment.
            return 'http://' . $_SERVER['HTTP_HOST'];
        }
    }
    
    // this will make site-root relitive links absolute (ex: '/login.php').
    // urls which are already absolute will remain unchanged.
    private static function makeAbsolute(string $url): string
    {
        // if the url is relative to the site root.
        // This checks if the URL starts with 1 slash (like '/foo.php'), but
        // not 2 (like '//google.com', which is a protocol-relative URL)
        if (preg_match('|^/(?!/)|', $url)) {
            // prepend the site's base URL (Location headers are absolute)
            $url = self::serverUrl() . $url;
        }
        
        return $url;
    }
    
    // redirect to a url which can be absolute or relative to the site root
    // ex: 'http://google.com' or '/login.php'. This stops script execution.
    public static function redirect(string $url): void
    {
        // escape the URL for a header and send it
        $absoluteUrl = self::makeAbsolute($url);
        $headerUrl = filter_var($absoluteUrl, FILTER_SANITIZE_URL);
        header("Location: $headerUrl");
        // escape the URL for a link and send it (this is considered good
        // form, but the automatic redirect should take care of most users)
        $href = htmlEntities($url, ENT_QUOTES);
        echo "<a href='$href'>Redirect</a>";
        
        // this function gets used to redirect users away from pages they
        // don't have access to, so make sure we don't send their browser
        // all the secret stuff anyway. auto-template still gets a chance to
        // template this.
        die();
    }
    
    // place this function at the top of a page you want to restrict access
    // to and give it an array of email addresses containing all the users,
    // groups, and OUs that should have access to the page. If a user is not
    // signed in they will be redirected to a sign in, then back to the
    // page. If they don't have access they will be shown which groups do.
    // This will end script execution if the user does not have access.
    public function requireAuthorizedUser(array $authorizedRoles): void
    {
        // get the current user email. This will redirect to a login if no
        // user is signed in.
        $userEmail = strtolower($this->currentUserEmail());
        
        // if the user's groups are not already cached in the session, fetch
        // them from Google and cache them in the session.
        if (!isset($_SESSION['EAAPEN_roles'])) {
            // a user is always a member of their own role. They are
            // also members of a role for any group or OU they are in.
            $_SESSION['EAAPEN_roles'] = array_merge(
                [$userEmail],
                $this->userGroups($userEmail),
                $this->userOUs($userEmail)
            );
        }
        $roles = $_SESSION['EAAPEN_roles'];
        
        // convert to lower case for consistent compares
        $authorizedRoles = array_map('strtolower', $authorizedRoles);
        $roles = array_map('strtolower', $roles);
        
        // check if the user's groups and the allowed groups overlap.
        // if so they have access.
        $hasAccess = array_intersect($roles, $authorizedRoles);
        
        // if the user does not have access give them a good error page
        // explaining why they don't have access.
        if (!$hasAccess) {
            header('HTTP/1.0 403 Forbidden');
            echo "You don't have permission to access this page.<br>\n";
            echo "You are signed in as $userEmail<br>\n";
            echo "You are a member of these roles:\n";
            echo "<ul>\n";
            foreach ($roles as $role) {
                echo "<li>$role</li>\n";
            }
            echo "</ul>\n";
            echo "Authorized roles:\n";
            echo "<ul>\n";
            foreach ($authorizedRoles as $authorizedGroup) {
                echo "<li>$authorizedGroup</li>\n";
            }
            echo "</ul>\n";
            die();
        }
    }
    
    // this will end the script and re-direct the user to a login flow
    public function startLogin(bool $returnToThisPage = true): void
    {
        if ($returnToThisPage) {
            // save the current page so we can re-direct to it in
            // finishLogin()
            $currentUrl = self::serverUrl() . $_SERVER['REQUEST_URI'];
            $_SESSION['EAAPEN_returnTo'] = $currentUrl;
        }
        
        $client = $this->newOAuthClient();
        // send the user back with the code
        $client->setRedirectUri($this->finishLoginUrl);
        
        // we only need to be able to get the user's email
        $client->setScopes([Google_Service_Oauth2::USERINFO_EMAIL]);
        
        self::redirect($client->createAuthUrl());
    }
    
    // this needs to be called by your $finishLoginUrl page. Pass
    // $_GET['code'] as $authCode. If $redirect is set to true (default)
    // this will try to redirect to the page the user was on when
    // startLogin() was called. If it doesn't have that information script
    // execution will continue.
    public function finishLogin(
        string $authCode,
        bool $redirect = true
    ): void {
        $client = $this->newOAuthClient();
        $client->setRedirectUri($this->finishLoginUrl);
        
        // We have gone to Google, and are back with an auth code.
        // trade our auth code for an access token.
        $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
        
        // Something went wrong with exchanging the code for an access token
        if (isset($accessToken['error'])) {
            error_log(
                "Error exchanging the oauth code for an access token: " .
                print_r($accessToken, true)
            );
            throw new Exception(
                'Error exchanging the oauth code for an access token'
            );
        }
        
        // get the user's email
        $userProfile = (new Google_Service_OAuth2($client))
            ->userinfo_v2_me
            ->get();
        $email = $userProfile->email;
        $domain = $userProfile->hd;
        $isVerified = $userProfile->verified_email;
        
        if (empty($this->gappsDomain)) {
            // if we are allowing general google accounts, at least make
            // sure they have a verified email
            if (!$isVerified) {
                throw new Exception('Unverified email');
            }
        } else {
            // if a gapps domain was specified for this app, check that the
            // account is a gapps account and is a member of the same domain
            if (empty($domain) || $domain != $this->gappsDomain) {
                throw new Exception('Account domain does not match');
            }
        }
        
        // save the user
        $_SESSION['EAAPEN_email'] = $email;
        
        if ($redirect) {
            // do we know where this user was before they got sent to log in
            if (!empty($_SESSION['EAAPEN_returnTo'])) {
                // don't redirect the user to the same place if they come
                // back to the log in page later
                $url = $_SESSION['EAAPEN_returnTo'];
                unset($_SESSION['EAAPEN_returnTo']);
                
                // send the user back from whence they came
                self::redirect($url);
            }
        }
    }
    
    // log out the current user. Note: this does NOT end script execution.
    // TODO: invalidate token on google side
    public function logout(): void
    {
        unset($_SESSION['EAAPEN_roles']);
        unset($_SESSION['EAAPEN_email']);
    }
    
    // redirect to a login flow to get a user account that will be used to
    // read google groups information. You must have finishAdminLoginUrl
    // set up to use this, and it only makes sense to use it if you are
    // building an app for internal use by your own gsuite users.
    public function startAdminLogin(): void
    {
        if (empty($this->finishAdminLoginUrl)) {
            throw new Exception('finishAdminLoginUrl is not set');
        }
        
        $client = $this->newOAuthClient();
        // send the user back with the code
        $client->setRedirectUri($this->finishAdminLoginUrl);
        
        // so we get a refresh token
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        // request profile (to verify domain) and read-only access to groups
        $client->setScopes([
            Google_Service_Oauth2::USERINFO_EMAIL,
            Google_Service_Directory::ADMIN_DIRECTORY_GROUP_READONLY,
            Google_Service_Directory::ADMIN_DIRECTORY_USER_READONLY
        ]);
        
        self::redirect($client->createAuthUrl());
    }
    
    // The script at finishAdminLoginUrl should call this function with
    // $_GET['code'] as $authCode. This will make sure the user has access
    // to read group information, then will save the refresh token so it can
    // be used to check group membership later. This will not end script
    // execution.
    public function finishAdminLogin(
        string $authCode,
        bool $checkAccess = true
    ): void {
        $client = $this->newOAuthClient();
        $client->setRedirectUri($this->finishAdminLoginUrl);
        
        // We have gone to Google, and are back with an auth code.
        // trade our auth code for an access token.
        $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
        
        // Something went wrong with exchanging the code for an access token
        if (isset($accessToken['error'])) {
            error_log(
                'Error exchanging the oauth code for an access token: ' .
                print_r($accessToken, true)
            );
            throw new Exception(
                'Error exchanging the oauth code for an access token'
            );
        }
        
        // get the user's domain
        $userProfile = (new Google_Service_OAuth2($client))
            ->userinfo_v2_me
            ->get();
        $email = $userProfile->email;
        $domain = $userProfile->hd;
        
        // check that our admin is a member of our app's domain
        if (empty($domain) || $domain != $this->gappsDomain) {
            throw new Exception('Account domain does not match');
        }
        
        // check if this access token grants the requested permissions
        $scopes = explode(' ', $accessToken['scope']);
        $hasGroupAccess = in_array(
            Google_Service_Directory::ADMIN_DIRECTORY_GROUP_READONLY,
            $scopes
        );
        $hasUserAccess = in_array(
            Google_Service_Directory::ADMIN_DIRECTORY_USER_READONLY,
            $scopes
        );
        if (!$hasGroupAccess || !$hasGroupAccess) {
            error_log(
                'Access token did not grant the requested permissions: ' .
                print_r($accessToken, true)
            );
            throw new Exception(
                'Access token did not grant the requested permissions'
            );
        }
        
        // optionally test that the token works before we overwrite the old
        // one. This can't detect cases where a user has only scoped access
        // to these APIs, but it at least makes sure they can view OU/groups
        // for their own account.
        if ($checkAccess) {
            try {
                $this->adminAccessToken = $accessToken;
                $this->userGroups($email);
                $this->userOU($email);
            } catch (Google_Service_Exception $exception) {
                error_log(
                    "Error using new token. It will not be saved.\n" .
                    print_r($exception, true)
                );
                throw $exception;
            }
        }
        
        // save the access token (which should include a refresh token)
        $this
            ->firestore
            ->kvWrite('EAAPEN_googleAdminAccessToken', $accessToken);
    }
    
    // Get the current user's email. If the user is not signed in you will
    // either get an empty string back or the user will be redirected to the
    // login depending on the value of $login
    public function currentUserEmail(bool $login = true): string
    {
        // if the user is not logged in
        if (empty($_SESSION['EAAPEN_email'])) {
            if ($login) {
                $this->startLogin();
            } else {
                return '';
            }
        }
        
        return $_SESSION['EAAPEN_email'];
    }
    
    // return true if the user is logged in
    public function isLoggedIn(): bool
    {
        return !empty($this->currentUserEmail(false));
    }
}
