<?php
namespace eaapen;

class Router
{
    private string $publicFolder;
    private string $includesFolder;
    private string $error404Page;
    
    public function __construct(
        string $publicFolder = 'public',
        string $includesFolder = 'includes',
        string $error404Page = '404.php'
    ) {
        $this->publicFolder = $publicFolder;
        $this->includesFolder = $includesFolder;
        $this->error404Page = $error404Page;
    }
    
    // return true if the file exists and is not a directory (there would be
    // a race condition here, but App Engine is a read-only filesystem)
    private static function isFile(string $path): bool
    {
        return file_exists($path) && !is_dir($path);
    }
    
    // pass $_SERVER['REQUEST_URI'] as $requestUri. This will try to find
    // the appropreate PHP file based on the URI, defaulting to index.php
    // in the case of folders. For any non-folder non-php file (aka any file
    // with an extension other that '.php') this will return false. This is
    // handy if you want to develop using PHP's built in web server.
    public function route($requestUri): bool
    {
        // get the path excluding query string and domain
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        
        // handle static assets with the default router in case we are running under
        // the built in PHP web server rather than App Engine
        $regex = '/\/(.+\.(?:|.|..|[^p]..|.[^h].|..[^p]|....+))$/';
        if (preg_match($regex, $path)) {
            // serve the requested resource as-is.
            return false;
        }
        
        // if an includes folder was listed and the folder exists
        $includesFolder = $this->includesFolder;
        if (!empty($includesFolderr) && file_exists($includesFolder)) {
            // include everything from the folder in alphabetical order
            $includes = glob(__DIR__ . "/$includesFolder/*.php");
            foreach (sort($includes) as $filename) {
                require_once $filename;
            }
        }
        
        // trim leading and trailing slashes
        $path = trim($path, '/');
        
        // if the requested path is a php file
        $publicFolder = $this->publicFolder;
        if (self::isFile(__DIR__ . "/$publicFolder/$path")) {
            require __DIR__ . "/public/$path";
            return true;
        }
        
        // if the requested path contains an index.php
        if (self::isFile(__DIR__ . "/$publicFolder/$path/index.php")) {
            require __DIR__ . "/public/$path/index.php";
            return true;
        }
        
        // fall back to 404 page
        require_once __DIR__ . "/{$this->error404Page}";
        return true;
    }
}
