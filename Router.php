<?php
namespace eaapen;

class Router
{
    private string $publicFolder;
    private string $includesFolder;
    private string $error404Page;
    
    // set $includesFolder to an empty string to not use an includes folder.
    public function __construct(
        ?string $publicFolder = null,
        ?string $includesFolder = null,
        string $error404Page = ''
    ) {
        // if no paths were given try to guess them based on the assumption
        // that we are in the composer vendor folder
        $publicFolder ??= dirname(__DIR__, 3) . '/public';
        $includesFolder ??= dirname(__DIR__, 3) . '/includes';
        
        $this->publicFolder = rtrim($publicFolder, '/');
        $this->includesFolder = rtrim($includesFolder, '/');
        $this->error404Page = $error404Page;
    }
    
    // return true if the file exists and is not a directory (there would be
    // a race condition here, but App Engine is a read-only filesystem)
    private static function isFile(string $path): bool
    {
        return !empty($path) && file_exists($path) && !is_dir($path);
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
        
        // take relative path from URL and make it an absolute filesystem
        // path based on $this->publicFolder.
        $path = trim($path, '/');
        $path = "{$this->publicFolder}/$path";
        $path = rtrim($path, '/');
        
        // if the requested path is a php file
        if (self::isFile($path)) {
            require $path;
            return true;
        }
        
        // if the requested path is a folder with an index.php
        if (self::isFile("$path/index.php")) {
            require "$path/index.php";
            return true;
        }
        
        // check if this is a path to a php file that is just missing the
        // '.php' extension
        if (self::isFile("$path.php")) {
            require "$path.php";
            return true;
        }
        
        // fall back to 404 page
        if (self::isFile($this->error404Page)) {
            require_once $this->error404Page;
        } else {
            http_response_code(404);
            echo '404 - file not found';
            error_log("404: $path");
        }
        return true;
    }
}
