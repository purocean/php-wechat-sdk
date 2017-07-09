<?php

namespace Wxsdk;

/**
 * 企业微信 SDK
 *
 * @link https://mp.weixin.qq.com/wiki?t=resource/res_main&id=mp1445241432
 */
class Mpwx
{
    /**
     * 配置
     *
     * @var array
     */
    private $_config = [
        'file_prefix' => '', // 缓存文件前缀
        'appid' => '',
        'secret' => '',
        'dataPath' => '', // 缓存数据存放目录
    ];

    public function __construct($config)
    {
        $this->_config = array_merge($this->_config, $config);
    }

    /**
     * 获取微信配置.
     *
     * @param string $name 配置名称
     *
     * @return string|array
     */
    public function getConfig($name = null)
    {
        if (!is_null($name)) {
            if (isset($this->_config[$name])) {
                return $this->_config[$name];
            } else {
                return null;
            }
        }

        return $this->_config;
    }

    /**
     * 获取 Access Token.
     *
     * @return string
     */
    public function getAccessToken()
    {
        return $this->getCachedValue('access_token', function () {
            return $this->_curl(
                'https://api.weixin.qq.com/cgi-bin/token',
                [
                    'grant_type' => 'client_credential',
                    'appid' => $this->getConfig('appid'),
                    'secret' => $this->getConfig('secret'),
                ]
            );
        });
    }

    /**
     * 获取网页授权 Access Token 和 Openid
     * 这里暂时不做缓存
     *
     * @param string $code 网页授权 code
     *
     * @return array|false
     */
    public function getAuth($code)
    {
        $result = $this->_curl(
            'https://api.weixin.qq.com/sns/oauth2/access_token',
            [
                'appid' => $this->getConfig('appid'),
                'secret' => $this->getConfig('secret'),
                'code' => $code,
                'grant_type' => 'authorization_code',
            ]
        );

        if ($result) {
            return [
                'access_token' => $result['access_token'],
                'openid' => $result['openid'],
            ];
        }

        return false;
    }


    /**
     * 获取微信 JSAPI Ticket.
     *
     * @return string
     */
    public function getJsApiTicket()
    {
        return $this->getCachedValue('ticket', function () {
            return $this->_curl(
                'https://api.weixin.qq.com/cgi-bin/ticket/getticket',
                [
                    'access_token' => $this->getAccessToken(),
                    'type' => 'jsapi',
                ]
            );
        });
    }

    /**
     * 获取缓存的值，access_token, jsapi_ticket
     *
     * @param string $key 键
     * @param Closure $call 如果缓存获取不到备用
     *
     * @return mixed 值
     */
    public function getCachedValue($key, $call = null)
    {
        // 从自己配置中取出未过期数据
        if ($this->getConfig($key) and $this->getConfig("{$key}_expiresed_at") > time()) {
            return $this->getConfig($key);
        }

        // 自己的配置中没有就从缓存文件中取
        $prefix = $this->getConfig('file_prefix');
        $cacheFile = $this->getConfig('dataPath')."/{$prefix}{$key}_cache.json"; // 缓存文件名
        $result = $this->_getCache($cacheFile);

        // 缓存文件也没有或者过期了，就从闭包获取
        if (!$result or !isset($result[$key]) and !is_null($call)) {
            $result = $call();

            // 获取成功了写入缓存文件
            if ($result and isset($result[$key])) {
                $result['time'] = time();
                $result['expires_in'] = 6200; // 6200 秒就更新
                file_put_contents($cacheFile, json_encode($result));
            }
        }

        // 更新自己的配置并返回
        if ($result and isset($result[$key])) {
            $this->_config[$key] = $result[$key];
            $this->_config["{$key}_expiresed_at"] = $result['expires_in'] + $result['time'];

            return $this->getConfig($key);
        }

        return null;
    }

    /**
     * 获取微信 OAth 认证跳转链接.
     *
     * @param string $uri 需要身份的地址
     * @param string $state 对接 state
     *
     */
    public function getJumpOAuthUrl($uri, $state = 'WechatOAuth')
    {
        return 'https://open.weixin.qq.com/connect/oauth2/authorize?appid='
            . $this->getConfig('appid').'&redirect_uri='
            . urlencode($uri)."&response_type=code&scope=snsapi_userinfo&state={$state}#wechat_redirect";
    }

    /**
     * 获取某个用户的信息.
     *
     * @param string $accessToken 网页授权的 Access Token，可由 getAuth 方法获取
     * @param string $openid 用户的 openid，可由 getAuth 方法获取
     *
     * @return array
     */
    public function getUserInfo($accessToken, $openid)
    {
        $apiUrl = 'https://api.weixin.qq.com/sns/userinfo';

        if ($result = $this->_curl($apiUrl, [
            'access_token' => $accessToken,
            'openid' => $openid,
            'lang' => 'zh_CN',
        ])) {
            unset($result['errcode'], $result['errmsg']);
            return $result;
        } else {
            return null;
        }
    }

    /**
     * 获取微信 JS API 签名包
     *
     * @param string $url 要签名的网址，不提供则为当前地址
     *
     * @return array 签名包
     */
    public function getJsApiPackage($url = null)
    {
        $jsapiTicket = $this->getJsApiTicket();

        if (is_null($url)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
            $url = $protocol.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
        }

        $timestamp = time();
        $nonceStr = base64_encode(openssl_random_pseudo_bytes(24));

        // 这里参数的顺序要按照 key 值 ASCII 码升序排序
        $string = "jsapi_ticket={$jsapiTicket}&noncestr={$nonceStr}&timestamp={$timestamp}&url={$url}";

        $signature = sha1($string);

        $signPackage = [
          'appid' => $this->getConfig('appid'),
          'nonceStr' => $nonceStr,
          'timestamp' => $timestamp,
          'url' => $url,
          'signature' => $signature,
          'rawString' => $string,
        ];

        return $signPackage;
    }

    /**
     * 向用户发送模板消息.
     *
     * @param string $tplId       模板 ID
     * @param array  $data        模板数据
     * @param string $url         链接地址
     * @param string $openid      用户的openid
     * @param string $topcolor    topcolor
     * @param string $accessToken access_token
     *
     * @return true|array
     */
    public function sendTplMsg($tplId, $data, $url, $openid, $topcolor = '#FF0000', $accessToken = null)
    {
        if (is_null($accessToken)) {
            $accessToken = $this->getAccessToken();
        }

        $postData = [
            'touser' => $openid,
            'template_id' => $tplId,
            'url' => $url,
            'topcolor' => $topcolor,
            'data' => $data,
        ];

        $apiUrl = 'https://api.weixin.qq.com/cgi-bin/message/template/send?access_token='.$accessToken;
        $result = $this->_curl($apiUrl, json_encode($postData), 'post');

        if (isset($result['errcode']) && $result['errcode'] == '0') {
            return true;
        } else {
            return $result;
        }
    }

    private function _getCache($file)
    {
        if (!file_exists($file)) {
            return null;
        }

        if (!$result = json_decode(file_get_contents($file), true)) {
            return null;
        }

        if (isset($result['time'])
            and isset($result['expires_in'])
            and (time() - $result['time']) < $result['expires_in']) {
            return $result;
        }

        return null;
    }

    /**
     * 简化的 HTTP 通信函数.
     *
     * @param string       $url    目标 URL
     * @param array|string $data   发送的数据
     * @param string       $method 发送方式
     *
     * @return array|boolen 获得的内容
     */
    private function _curl($url, $data = '', $method = 'get')
    {
        $parStr = '';
        if (is_array($data)) {
            $parStr = http_build_query($data);
        } else {
            $parStr = $data;
        }

        if (strtolower($method) == 'get') {
            $json = file_get_contents(rtrim($url, '?').'?'.$parStr);
        } else {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $parStr);
            $json = curl_exec($ch);
            curl_close($ch);
        }

        $result = json_decode($json, true);

        if (isset($result['errcode']) and $result['errcode'] != 0) {
            $this->_log("Error-{$method}-{$url}", $json, $data);
            return false;
        }

        return $result;
    }

    private function _log($title, $content = '', $data = '')
    {
        $data = var_export($data, true);
        $log = date("Y-m-d H:i:s") . " ---- {$title}\n{$data}\n\n{$content}\n\n";

        return file_put_contents(
            $this->getConfig('dataPath').'/' . $this->getConfig('file_prefix') . 'mpwx.log',
            $log,
            FILE_APPEND
        );
    }
}
