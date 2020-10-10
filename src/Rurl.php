<?php
/******************************************************************************************************************
** curl 请求网络
******************************************************************************************************************/
namespace ToolPackage;

class Rurl
{
    /**
     * 创建目录文件错误
     */
    const ERROR_CREATE_FILE = 9001;

    public static $obj = null;

    /**
     * 错误编码
     */
    public $errorNum = 0;

    /**
     * 错误信息
     */
    public $errorMessage = '';

    /**
     * 当前正在使用的uri
     */
    public $currentUri = '';

    /**
     * 解析后 uri 信息
     */
    public $currentUriInfo = [];

    /**
     * 超时时间（秒）
     */
    private $_timeout = 30;

    /**
     * 超时重新请求此时
     */
    private $_maxRequest = 3;

    /**
     * cookie缓存目录
     */
    private $_cookieDir = '';

    /**
     * curl句柄
     * @var handle
     */
    public $curl = null;

    /**
     * 请求成功后的结果
     * @var text
     */
    public $contents;

    /**
     * curl option
     * @var array
     */
    protected $curlOptions = [];

    /**
     * 请求完成后回调
     * @param $rurl Rurl实例对象
     */
    protected $onFinished;

    /**
     * 请求失败后回调
     * @param $rurl Rurl实例对象
     */
    protected $onError;

    protected function __construct()
    {
        $this->curl = curl_init();
    }

    /**
     * 入口
     */
    public static function make()
    {
        static::$obj || static::$obj = new static();
        return static::$obj;
    }

    /**
     * 设置错误信息
     * @param string $msg 错误信息
     */
    public function setErrorMessage($msg)
    {
        $this->errorMessage = $msg;
    }

    /**
     * 设置错误编码
     * @param int $errno
     */
    public function setErrorNum($errno)
    {
        $this->errorNum = $errno;
    }

    /**
     * 设置错误信息
     * @param int $errno
     * @param string $msg
     */
    public function setErrorInfo($errno=0, $msg='')
    {
        $this->setErrorNum($errno);
        $this->setErrorMessage($msg);
    }


    /**
     * 设置curlOption
     * @param array $option 
     */
    public function setOptions($option = [])
    {
        $this->curlOptions = $option;
    }

    /**
     * 设置请求头
     * @param array headers
     */
    public function setHeaders($headers = [])
    {
        $this->curlOptions[CURLOPT_HTTPHEADER] = $headers;
    }

    /**
     * 设置存储cookie的文件(下次请求会覆盖上次的内容)
     * @param string $filename cookie文件名
     */
    public function setCookieJar($filename)
    {
        if(!file_exists($filename))
        {
            $this->createFile($filename);
        }
        $this->curlOptions[CURLOPT_COOKIEJAR] = $filename;
    }

    /**
     * 设置包含 cookie 数据的文件
     * @param string $cookie_file
     */
    public function setCookieFile($cookie_file)
    {
        $this->curlOptions[CURLOPT_COOKIEFILE] = $cookie_file;
    }

    /**
     * 设置响应头的保存文件
     */
    public function setResponseHeaderFile($filename)
    {
        $this->curlOptions[CURLOPT_WRITEHEADER] = $filename;
    }

    /**
     * 设置cookie保存目录
     * @param string $path
     */
    public function setCookieDir($path)
    {
        $this->_cookieDir = $path;
    }

    /**
     * 创建文件
     */
    public function createFile($filename)
    {
        $dirname = dirname($filename);
        if(!file_exists($dirname))
        {
            $succ = mkdir($dirname, 0777, true);
            if(!$succ)
            {
                $this->setErrorInfo(self::ERROR_CREATE_FILE, '尝试创建目录' . $dirname . '失败');
            }
            $succ = touch($filename);
            if(!$succ)
            {
                $this->setErrorInfo(self::ERROR_CREATE_FILE, '尝试创建文件' . $filename . '失败');
            }
        }
    }

    /**
     * 解析cookie头
     */
    public function parseCookieHeader($cookie_header)
    {
        $content = substr($cookie_header, 11); 
        $info_list = explode(';', trim($content));
        $kv = array_shift($info_list);
        list($key, $value) = explode('=', $kv);
        $cookie = [
            'key' => $key,
            'value' => $value,
            'kv' => $kv
        ];
        foreach($info_list as $item)
        {
            list($k, $v) = explode('=', trim($item));
            $cookie[$k] = $v;
        }
        
        if(empty($cookie['path']) || $cookie['path'][0] != '/')
        {
            $cookie['path'] = '/';
            if(!empty($this->currentUriInfo['path']))
            {
                if(substr($this->currentUriInfo['path'],-1) == '/')
                {
                    $dirname = substr($this->currentUriInfo['path'], 0, -1);
                }
                $dirname = str_replace('\\', '/', dirname($this->currentUriInfo['path']));
                $cookie['path'] = $dirname;
            }
        }
        return $cookie;
    }

    /**
     * 解析url中path的目录
     * 如果path最后不是以 / 结尾,则最后的名称当做文件名处理
     * @return string 目录路径
     */
    public function getDirname()
    {
        if(!empty($this->currentUriInfo['path']))
        {
            if(substr($this->currentUriInfo['path'],-1) == '/')
            {
                $dirname = substr($this->currentUriInfo['path'], 0, -1);
            }
            $dirname = str_replace('\\', '/', dirname($this->currentUriInfo['path']));
        }
        else
        {
            $dirname = '/';
        }
        return $dirname;
    }


    /**
     * 回调函数 headerfunction
     */
    public function headerFunction($cp, $header)
    {
        if(substr($header, 0, 11) === 'Set-Cookie:'){
            $cookie = $this->parseCookieHeader($header);
            $cookies = $this->getStoredCookie();
            $cookies[$cookie['key']] = $cookie;
            $this->storeCookie($cookies);
        }
        return strlen($header);
    }

    /**
     * 获取已存储的cookie
     */
    protected function getStoredCookie()
    {
        $cookie = [];
        $cookie_file = $this->getCacheCookieFile();
        if(file_exists($cookie_file))
        {
            $data = file_get_contents($cookie_file);
            $data and $cookie = json_decode($data, true);
        }
        return $cookie;
    }

    /**
     * 保存cookie
     */
    protected function storeCookie($cookies)
    {
        $cookie_file = $this->getCacheCookieFile();
        if(!file_exists($cookie_file))
        {
            $this->createFile($cookie_file);
        }
        file_put_contents($cookie_file, json_encode($cookies));
    }

    /**
     * 获取缓存cookie_file
     */
    protected function getCacheCookieFile()
    {
        $data = parse_url($this->currentUri);
        return rtrim($this->_cookieDir, '\/') . '/' . md5($data['scheme'].$data['host']); 
    }

    /**
     * 请求完成后的动作
     * @param callable $action
     */
    public function setOnFinished(callable $action)
    {
        $this->onFinished = $action;
    }

    /**
     * 请求失败后的动作
     * @param callable $action
     */
    public function setOnError(callable $action)
    {
        $this->onError = $action;
    }

    /**
     * 通过url获取数据
     * @param String $url 
     * @param Array $options curl的option设置
     * @param Number $maxnum 尝试连接的次数
     */
    public function exec($url, $options=array())
    {
        $this->currentUri = $url;
        $this->currentUriInfo = parse_url($url);
        //是否自动发送cookie
        $this->autoSendCookie();
        //是否缓存cookie
        $this->cacheCookie();

        curl_setopt_array($this->curl, $this->curlOptions);
        //set_time_limit(60);
        $opt = array(
            CURLOPT_URL => $url,
            CURLOPT_TIMEOUT => $this->_timeout,
            CURLOPT_RETURNTRANSFER => true,
        );
        
        curl_setopt_array($this->curl, $opt);

        if($options){
            curl_setopt_array($this->curl, $options); 
        }
        
        if(!empty($this->currentUriInfo['scheme']) && $this->currentUriInfo['scheme'] == 'https'){
            curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
        }
        $start_time = date('Y-m-d H:i:s');
        $contents = curl_exec($this->curl);
        $num = 1;
        while(curl_errno($this->curl) === 28){
            if($num > $this->_maxRequest){
                break;
            }
            $num++;
            $start_time = date('Y-m-d H:i:s');
            $contents = curl_exec($this->curl);
        }
        $errno = curl_errno($this->curl);
        $error = curl_error($this->curl);
        $this->setErrorInfo($errno, $error);
        curl_close($this->curl);
        
        /* if(isset($options[CURLOPT_POSTFIELDS])){
         *     if(is_array($options[CURLOPT_POSTFIELDS])){
         *         $param_str = json_encode($options[CURLOPT_POSTFIELDS]);
         *     }else{
         *         $param_str = $options[CURLOPT_POSTFIELDS];
         *     }
         *     $param_str = '参数：' . $param_str;
         * }else{
         *     $param_str = '';
         * } */

        if($error)
        {
            if($this->onError)
            {
                ($this->onError)($this);
            }
        }
        else
        {
            $this->contents = $contents;
            if($this->onFinished)
            {
                ($this->onFinished)($this);
            }
        }
    }

    /**
     * 缓存cookie
     */
    protected function cacheCookie()
    {
        if($this->_cookieDir)
        {
            $this->curlOptions[CURLOPT_HEADERFUNCTION] = [$this, 'headerFunction'];
        }
    }

    /**
     * 自动发送cookie
     */
    protected function autoSendCookie()
    {
        $cookie_data = $this->getStoredCookie();
        if($cookie_data)
        {
            $cookie_list = [];
            foreach($cookie_data as $info)
            {
                if($this->cookieIsValid($info))
                {
                    $cookie_list[] = $info['kv'];
                }
            }
            if($cookie_list)
            {
                $this->curlOptions[CURLOPT_COOKIE] = implode('; ', $cookie_list);
            }
        }
    }

    /**
     * 检查cookie 是否有效
     * @param array $info 一条cookie数据信息
     */
    public function cookieIsValid(&$info)
    {
        if(isset($info['expires']))
        {
            $unix_time = intval(strtotime($info['expires']));
            $unix_time = $unix_time - 3600*8;
            
            if($unix_time <= time())
            {
                return false;
            }
        }
        if(!empty($info['path']) && $info['path'] != '/' && $info['path'][0] == '/')
        {
            $preg = preg_quote(rtrim($info['path'], '/'), '/');
            $match_num = preg_match('/^' . $preg . '/', $this->currentUriInfo['path']);
            if($match_num == 0)
            {
                return false;
            }

        }
        return true;
    }

    /**
     * 获取错误信息
     */
    public function getErrorInfo()
    {
        return $this -> errorInfo;
    }

    /**
     * 将请求参数放到url上
     * @param String $url 
     * @param String or Array $param 参数
     */
    public function setUrlParam($url, $param = array()){
        $p = '';
        
        if(is_array($param)){
            foreach($param as $k=>$v){
                $p .= "{$k}=".urlencode($v)."&";
            }
            $p = trim($p, '&');
        }else if(is_string($param)){
            $p .= $param;
        }
        
        $uinfo = parse_url($url);
        $new_url = '';
        !empty($uinfo['scheme']) && $new_url .= $uinfo['scheme'].'://';
        !empty($uinfo['host']) && $new_url .= $uinfo['host'];
        !empty($uinfo['port']) && $new_url .= ':' . $uinfo['port'];
        !empty($uinfo['path']) && $new_url .= $uinfo['path'];
        
        $query = '';
        if(!empty($uinfo['query'])){
            $query .= '?'.$uinfo['query'];
        }
        if($p){
            if($query){
                $query .= '&'.$p;
            }else{
                $query .= '?'.$p;
            }
        }
        $new_url .= $query;
        
        !empty($uinfo['fragment']) && $new_url .= '#'.$uinfo['fragment'];
        return $new_url;
    }

    /**
     * get方式 获取数据
     * @param String $url
     * @param $param 字符串 或 数组
     */
    public function get($url, $param = array(),$opt=array()){
        $opt[CURLOPT_HTTPGET] = true;
        $url = $this -> setUrlParam($url, $param);
        //echo $url;exit;
        return $this -> exec($url,$opt);
    }

    /**
     * post方式 获取数据
     * @param String $url
     * @param $param 数组
     */
    public function post($url, $param = array(), $opt=array()){
        $opt[CURLOPT_POST] = true;
        $opt[CURLOPT_POSTFIELDS] = $param;
        return $this -> exec($url, $opt);
    }

    /**
     * 自定义 方式 获取数据
     * @param String $url
     * @param String $method 自定义的传输方式
     * @param $param 数组
     */
    public function methodInterface($url, $method, $param = array(), $opt = array()){
        $opt[CURLOPT_CUSTOMREQUEST] = $method;
        $opt[CURLOPT_POSTFIELDS] = $param;
        return $this -> exec($url, $opt);
    }


}
