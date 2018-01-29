<?php
namespace Scripto\Mediawiki;

use Scripto\Mediawiki\Exception;
use Zend\Http\Client as HttpClient;
use Zend\Http\Request;
use Zend\Session\Container;

/**
 * MediaWiki API client
 */
class ApiClient
{
    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * MediaWiki API endpoint URL
     *
     * @var string
     */
    protected $apiUrl;

    /**
     * @var Container
     */
    protected $session;

    /**
     * Cache of MediaWiki site information
     *
     * @var array
     */
    protected $siteInfo;

    /**
     * Cache of MediaWiki user information
     *
     * @var array
     */
    protected $userInfo;

    /**
     * Construct the client.
     *
     * @param HttpClient $client
     * @param string $apiUrl
     */
    public function __construct(HttpClient $httpClient, $apiUrl)
    {
        $this->httpClient = $httpClient;
        $this->apiUrl = $apiUrl;

        // Retrieve persisted MediaWiki cookies and add them to the HTTP client.
        $this->session = new Container('ScriptoMediawiki');
        if (is_array($this->session->cookies)) {
            foreach ($this->session->cookies as $cookie) {
                $this->httpClient->addCookie($cookie);
            }
        }

        // Set MediaWiki site and user information.
        $this->siteInfo = $this->querySiteInfo();
        $this->userInfo = $this->queryUserInfo();
    }

    /**
     * Is this page created?
     *
     * @param string|array $title A string or the result of self::queryPage()
     * @return bool
     */
    public function pageIsCreated($title)
    {
        if (is_string($title)) {
            $page = $this->queryPage($title);
        } elseif (is_array($title)) {
            $page = $title;
        } else {
            throw new Exception\InvalidArgumentException('A title must be a string or an array');
        }
        return isset($page['pageid']);
    }

    /**
     * Can the user perform this action on this page?
     *
     * Find the available actions in self::queryPages() under intestactions.
     *
     * @param string|array $title A string or the result of self::queryPage()
     * @param string $action
     * @return bool
     */
    public function userCan($title, $action)
    {
        if (is_string($title)) {
            $page = $this->queryPage($title);
        } elseif (is_array($title)) {
            $page = $title;
        } else {
            throw new Exception\InvalidArgumentException('A title must be a string or an array');
        }
        return isset($page['actions'][$action])
            ? (bool) $page['actions'][$action] : false;
    }

    /**
     * Is the current user logged in?
     *
     * @return bool
     */
    public function userIsLoggedIn()
    {
        return isset($this->userInfo) ? (bool) $this->userInfo['id'] : false;
    }

    /**
     * Query information about a named user.
     *
     * @param string $name User name
     * @return array
     */
    public function queryUser($name)
    {
        return $this->queryUsers([$name])[0];
    }

    /**
     * Query information about named users.
     *
     * @link https://www.mediawiki.org/wiki/API:Users
     * @param array $names User names
     * @return array
     */
    public function queryUsers(array $names)
    {
        if (count($names) !== count(array_unique($names))) {
            throw new Exception\InvalidArgumentException('Names must be unique');
        }
        foreach ($names as $name) {
            if (!is_string($name)) {
                throw new Exception\InvalidArgumentException('A name must be a string');
            }
            if (strstr($name, '|')) {
                throw new Exception\InvalidArgumentException('A name must not contain a vertical bar');
            }
        }

        $query = $this->request([
            'action' => 'query',
            'list' => 'users',
            'ususers' => implode('|', $names),
            'usprop' => 'blockinfo|groups|implicitgroups|rights|editcount|registration|emailable|gender',
        ]);
        if (isset($query['error'])) {
            throw new Exception\QueryException($query['error']['info']);
        }
        return $query['query']['users'];
    }

    /**
     * Query information about all users.
     *
     * @link https://www.mediawiki.org/wiki/API:Allusers
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function queryAllUsers($offset = null, $limit = null)
    {
        if (isset($limit) && !is_numeric($limit)) {
            throw new Exception\InvalidArgumentException('A limit must be numeric');
        }
        if (isset($offset) && !is_numeric($offset)) {
            throw new Exception\InvalidArgumentException('An offset must be numeric');
        }

        $users = [];
        $continue = false;
        do {
            $request = [
                'action' => 'query',
                'list' => 'allusers',
                'aulimit' => 500,
                'auprop' => 'blockinfo|groups|implicitgroups|rights|editcount|registration',
            ];
            if ($continue) {
                // The previous iteration returned a continue query.
                $request['continue'] = $query['continue']['continue'];
                $request['aufrom'] = $query['continue']['aufrom'];
            }
            $query = $this->request($request);
            if (isset($query['error'])) {
                throw new Exception\QueryException($query['error']['info']);
            }
            $users = array_merge($users, $query['query']['allusers']);
            $continue = isset($query['continue']);
        } while ($continue);

        // We get all users before slicing out what was requested because the
        // API does not provide a conventional limit/offset query. This way is
        // rather unoptimized but it offers a simpler and more predictable
        // interface with only a minor speed reduction.
        return array_slice($users, $offset, $limit);
    }

    /**
     * Query information about a page, including its latest revision.
     *
     * @param string $title Page title
     * @return array
     */
    public function queryPage($title)
    {
        return $this->queryPages([$title])[0];
    }

    /**
     * Query information about pages, including their latest revisions.
     *
     * @link https://www.mediawiki.org/wiki/API:Info
     * @link https://www.mediawiki.org/wiki/Manual:User_rights#List_of_permissions
     * @param array $titles Page titles
     * @return array
     */
    public function queryPages(array $titles)
    {
        if (count($titles) !== count(array_unique($titles))) {
            throw new Exception\InvalidArgumentException('Titles must be unique');
        }
        foreach ($titles as $title) {
            if (!is_string($title)) {
                throw new Exception\InvalidArgumentException('A title must be a string');
            }
            if (strstr($title, '|')) {
                throw new Exception\InvalidArgumentException('A title must not contain a vertical bar');
            }
        }

        $pages = [];
        // The API limits titles to 50 per query.
        foreach (array_chunk($titles, 50) as $titleChunk) {
            $query = $this->request([
                'action' => 'query',
                'prop' => 'info|revisions',
                'titles' => implode('|', $titleChunk),
                'inprop' => 'protection|url',
                'rvprop' => 'content|ids|flags|timestamp|comment|user',
                'intestactions' => 'read|edit|createpage|createtalk|protect|rollback',
            ]);
            if (isset($query['error'])) {
                throw new Exception\QueryException($query['error']['info']);
            }

            // The ordering of the response does not necessarily correspond to
            // the ordering of the input. Here we match the original ordering.
            $normalized = [];
            if (isset($query['query']['normalized']) ) {
                foreach ($query['query']['normalized'] as $value) {
                    $normalized[$value['from']] = $value['to'];
                }
            }
            foreach ($titleChunk as $title) {
                $title = (string) $title;
                $normalizedTitle = isset($normalized[$title]) ? $normalized[$title] : $title;
                foreach ($query['query']['pages'] as  $page) {
                    if ($page['title'] === $normalizedTitle) {
                        $pages[] = $page;
                        continue;
                    }
                }
            }
        }
        return $pages;
    }

    /**
     * Query page revisions.
     *
     * @link https://www.mediawiki.org/wiki/API:Revisions
     * @param string $title
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function queryRevisions($title, $limit = null, $offset = null)
    {
        if (!is_string($title)) {
            throw new Exception\InvalidArgumentException('A title must be a string');
        }
        if (strstr($title, '|')) {
            throw new Exception\InvalidArgumentException('A title must not contain a vertical bar');
        }
        if (isset($limit) && !is_numeric($limit)) {
            throw new Exception\InvalidArgumentException('A limit must be numeric');
        }
        if (isset($offset) && !is_numeric($offset)) {
            throw new Exception\InvalidArgumentException('An offset must be numeric');
        }

        $revisions = [];
        $continue = false;
        do {
            $request = [
                'action' => 'query',
                'prop' => 'revisions',
                'titles' => $title,
                'rvprop' => 'ids|flags|timestamp|user|size|parsedcomment',
                'rvlimit' => 'max',
            ];
            if ($continue) {
                // The previous iteration returned a continue query.
                $request['continue'] = $query['continue']['continue'];
                $request['rvcontinue'] = $query['continue']['rvcontinue'];
            }
            $query = $this->request($request);
            if (isset($query['error'])) {
                throw new Exception\QueryException($query['error']['info']);
            }
            if (isset($query['query']['pages'][0]['revisions'])) {
                $revisions = array_merge($revisions, $query['query']['pages'][0]['revisions']);
            }
            $continue = isset($query['continue']);
        } while ($continue);

        // We get all revisions before slicing out what was requested because
        // the API does not provide a conventional limit/offset query. This way
        // is rather unoptimized but it offers a simpler and more predictable
        // interface with only a minor speed reduction.
        return array_slice($revisions, $offset, $limit);
    }

    /**
     * Edit or create a page.
     *
     * @link https://www.mediawiki.org/wiki/API:Edit
     * @param string $title
     * @param string $text
     * @return array The successful edit result
     */
    public function editPage($title, $text)
    {
        if (!is_string($title)) {
            throw new Exception\InvalidArgumentException('Page title must be a string');
        }
        if (!is_string($text)) {
            throw new Exception\InvalidArgumentException('Page text must be a string');
        }
        $query = $this->request([
            'action' => 'query',
            'meta' => 'tokens',
            'type' => 'csrf'
        ]);
        $page = $this->queryPage($title);
        $edit = $this->request([
            'action' => 'edit',
            'title' => $title,
            'text' => $text,
            'token' => $query['query']['tokens']['csrftoken'],
            // Use the timestamp of the base revision to detect edit conflicts.
            'basetimestamp' => isset($page['revisions']) ? $page['revisions'][0]['timestamp'] : null,
        ]);
        if (isset($edit['error'])) {
            throw new Exception\EditException($edit['error']['info']);
        }
        return $edit['edit'];
    }

    /**
     * Parse page wikitext into HTML.
     *
     * @link https://www.mediawiki.org/wiki/API:Parsing_wikitext
     * @param string $title
     * @return string The page HTML
     */
    public function parsePage($title)
    {
        if (!is_string($title)) {
            throw new Exception\InvalidArgumentException('Page title must be a string');
        }
        $parse = $this->request([
            'action' => 'parse',
            'page' => $title,
            'prop' => 'text',
            'disablelimitreport' => true,
            'disableeditsection' => true,
            'disabletoc' => true,
        ]);
        if (isset($parse['error'])) {
            throw new Exception\ParseException($parse['error']['info']);
        }
        return $parse['parse']['text'];
    }

    /**
     * Compare page revisions.
     *
     * @param int $fromRevId The first revision ID to compare
     * @param int $toRevId The second revision ID to compare
     * @return string
     */
    public function compareRevisions($fromRevId, $toRevId)
    {
        if (!is_numeric($fromRevId) || !is_numeric($toRevId)) {
            throw new Exception\InvalidArgumentException('Revision IDs must be numeric');
        }
        $compare = $this->request([
            'action' => 'compare',
            'fromrev' => $fromRevId,
            'torev' => $toRevId,
        ]);
        if (isset($compare['error'])) {
            throw new Exception\ParseException($compare['error']['info']);
        }
        return $compare['compare']['body'];
    }

    /**
     * Query information about the MediaWiki site.
     *
     * @link https://www.mediawiki.org/wiki/API:Siteinfo
     * @return array
     */
    public function querySiteInfo()
    {
        $query = $this->request([
            'action' => 'query',
            'meta' => 'siteinfo',
        ]);
        return $query['query']['general'];
    }

    /**
     * Get the most recently queried site information.
     *
     * @return array
     */
    public function getSiteInfo()
    {
        return $this->siteInfo;
    }

    /**
     * Query information about the current MediaWiki user.
     *
     * @link https://www.mediawiki.org/wiki/API:Userinfo
     * @return array
     */
    public function queryUserInfo()
    {
        $query = $this->request([
            'action' => 'query',
            'meta' => 'userinfo',
        ]);
        return $query['query']['userinfo'];
    }

    /**
     * Get the most recently queried user information.
     *
     * @return array
     */
    public function getUserInfo()
    {
        return $this->userInfo;
    }

    /**
     * Create a MediaWiki account using the default requests.
     *
     * @link https://www.mediawiki.org/wiki/API:Account_creation
     * @param string $username Username for authentication
     * @param string $password Password for authentication
     * @param string $retype Retype password
     * @param string $email Email address
     * @param string $realname Real name of the user
     * @return array The successful create account result
     */
    public function createAccount($username, $password, $retype, $email, $realname)
    {
        $query = $this->request([
            'action' => 'query',
            'meta' => 'tokens',
            'type' => 'createaccount'
        ]);
        $createaccount = $this->request([
            'action' => 'createaccount',
            'createreturnurl' => 'http://example.com/', // currently unused but required
            'createtoken' => $query['query']['tokens']['createaccounttoken'],
            'username' => $username,
            'password' => $password,
            'retype' => $password,
            'email' => $email,
            'realname' => $realname,
        ]);
        if (isset($createaccount['error'])) {
            throw new Exception\CreateaccountException($createaccount['error']['info']);
        }
        if ('FAIL' === $createaccount['createaccount']['status']) {
            throw new Exception\CreateaccountException($createaccount['createaccount']['message']);
        }
        return $createaccount['createaccount'];
    }

    /**
     * Log in to MediaWiki using the default requests.
     *
     * @link https://www.mediawiki.org/wiki/API:Login
     * @param string $username Username for authentication
     * @param string $password Password for authentication
     * @return array The successful login result
     */
    public function login($username, $password)
    {
        $query = $this->request([
            'action' => 'query',
            'meta' => 'tokens',
            'type' => 'login'
        ]);
        $clientlogin = $this->request([
            'action' => 'clientlogin',
            'loginreturnurl' => 'http://example.com/', // currently unused but required
            'logintoken' => $query['query']['tokens']['logintoken'],
            'username' => $username,
            'password' => $password,
        ]);
        if (isset($clientlogin['error'])) {
            throw new Exception\ClientloginException($clientlogin['error']['info']);
        }
        if ('FAIL' === $clientlogin['clientlogin']['status']) {
            throw new Exception\ClientloginException($clientlogin['clientlogin']['message']);
        }
        // Persist the authentication cookies.
        $this->session->cookies = $this->httpClient->getCookies();
        // Set user information.
        $this->userInfo = $this->queryUserInfo();
        return $clientlogin['clientlogin'];
    }

    /**
     * Log out of MediaWiki.
     *
     * @link https://www.mediawiki.org/wiki/API:Logout
     */
    public function logout()
    {
        $this->request(['action' => 'logout']); // Log out of MediaWiki
        $this->httpClient->clearCookies(); // Clear HTTP client cookies
        $this->session->cookies = null; // Clear session cookies
        $this->userInfo = $this->queryUserInfo(); // Reset MediaWiki user information
    }

    /**
     * Make a HTTP request
     *
     * Returns JSON response format version 2.
     *
     * @link https://www.mediawiki.org/wiki/API:JSON_version_2
     * @param array $params
     * @return array
     */
    public function request(array $params = [])
    {
        $params['format'] = 'json';
        $params['formatversion'] = '2';

        $request = new Request;
        $request->setUri($this->apiUrl);
        $request->setMethod(Request::METHOD_POST);
        $request->getPost()->fromArray($params);

        $response = $this->httpClient->send($request);
        if ($response->isSuccess()) {
            return json_decode($response->getBody(), true);
        }
        throw new Exception\RequestException($response->renderStatusLine());
    }
}
