<?php

namespace Wxsdk;

/**
 * 企业微信 SDK
 *
 * @link http://qydev.weixin.qq.com/wiki/index.php?title=%E9%A6%96%E9%A1%B5
 */
class Qywx
{
    /**
     * 配置
     *
     * @var array
     */
    private $_config = [
        'safe' => '0', // 发送消息是否保密
        'corpid' => '',
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
     * 获取Access Token.
     *
     * @return string
     */
    public function getAccessToken()
    {
        $accessTokenFile = $this->getConfig('dataPath').'/access_token_cache.json'; // 缓存文件名
        if (!$this->getConfig('access_token')) {
            $update = true;

            if (file_exists($accessTokenFile)) {
                $result = json_decode(file_get_contents($accessTokenFile), true);
                if (isset($result['access_token']) && isset($result['time'])
                        && isset($result['expires_in'])
                        && (time() - $result['time']) < $result['expires_in']) {
                    $this->_config['access_token'] = $result['access_token'];
                    $update = false;
                }
            }

            if ($update) {
                $resultJson = $this->_curl(
                    'https://qyapi.weixin.qq.com/cgi-bin/gettoken',
                    [
                        'corpid' => $this->getConfig('corpid'),
                        'corpsecret' => $this->getConfig('secret'),
                    ]
                );
                $result = json_decode($resultJson, true);
                $result['time'] = time();
                $result['expires_in'] -= 1000; // 6200 秒就更新
                file_put_contents($accessTokenFile, json_encode($result));
            }

            if (isset($result['access_token'])) {
                $this->_config['access_token'] = $result['access_token'];
            }
        }

        return $this->getConfig('access_token');
    }

    /**
     * 发送文本消息.
     *
     * @param string $text       要发送的内容
     * @param array  $toUserList 发送给的对象例 array('touser': "userid1|userid2",
     *                           'topart':'partid1') 参考 http://qydev.weixin.qq.com/wiki/index.php?title=%E6%B6%88%E6%81%AF%E7%B1%BB%E5%9E%8B%E5%8F%8A%E6%95%B0%E6%8D%AE%E6%A0%BC%E5%BC%8F#text.E6.B6.88.E6.81.AF
     * @param string $agentId    企业应用的ID
     *
     * @return array json 参考 http://qydev.weixin.qq.com/wiki/index.php?title=%E5%8F%91%E9%80%81%E6%8E%A5%E5%8F%A3%E8%AF%B4%E6%98%8E
     */
    public function sendTextMsg($text, $toUserList, $agentId)
    {
        $to = $this->_parseUserList($toUserList);
        $postData = [
            'msgtype' => 'text',
            'agentid' => (string) $agentId,
            'text' => [
                'content' => $text,
            ],
            'safe' => $this->getConfig('safe'),
        ];

        return $this->sendMsg(array_merge($to, $postData));
    }

    /**
     * 发送 News 消息.
     *
     * @param array  $articles   要发送的文章
     * @param array  $toUserList 发送给的对象例 ['touser': "userid1|userid2",
     *                           'topart':'partid1'] 参考 http://qydev.weixin.qq.com/wiki/index.php?title=%E6%B6%88%E6%81%AF%E7%B1%BB%E5%9E%8B%E5%8F%8A%E6%95%B0%E6%8D%AE%E6%A0%BC%E5%BC%8F#text.E6.B6.88.E6.81.AF
     * @param string $agentId    企业应用的ID
     *
     * @return string 腾讯服务器返回的 json 参考 http://qydev.weixin.qq.com/wiki/index.php?title=%E5%8F%91%E9%80%81%E6%8E%A5%E5%8F%A3%E8%AF%B4%E6%98%8E
     */
    public function sendNewsMsg($articles, $toUserList, $agentId)
    {
        $to = $this->_parseUserList($toUserList);
        $postData = [
            'msgtype' => 'news',
            'agentid' => (string) $agentId,
            'news' => [
                'articles' => $articles,
            ],
            'safe' => $this->getConfig('safe'),
        ];

        return $this->sendMsg(array_merge($to, $postData));
    }

    /**
     * 创建一条图文消息数组.
     *
     * @param string $title       标题
     * @param string $description 描述
     * @param string $url         链接到的网址
     * @param string $picurl      题图资源地址
     *
     * @return array 一条图文消息的数组
     */
    public function buildNewsItem($title, $description, $url, $picurl)
    {
        return [
            'title' => $title,
            'description' => $description,
            'url' => $url,
            'picurl' => $picurl,
        ];
    }

    /**
     * 发送消息.
     *
     * @param array $msg 要发送的内容 json
     *
     * @return array 腾讯服务器返回的 json 参考 http://qydev.weixin.qq.com/wiki/index.php?title=%E5%8F%91%E9%80%81%E6%8E%A5%E5%8F%A3%E8%AF%B4%E6%98%8E
     */
    public function sendMsg($msg)
    {
        $apiUrl = 'https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token='
            .$this->getAccessToken();
        $postStr = json_encode($msg, JSON_UNESCAPED_UNICODE);

        return json_decode($this->_curl($apiUrl, $postStr, 'post'), true);
    }

    /**
     * 获取部门成员列表.
     *
     * @param string $departmentId 部门ID
     * @param bool   $fetchChild   是否递归获取子部门的成员
     * @param int    $status       0获取全部成员，1获取已关注成员列表
     *                             2获取禁用成员列表，4获取未关注成员列表。status可叠加
     *
     * @return array 获取到的数据
     */
    public function getDepartmentMember($departmentId, $fetchChild = false, $status = 0)
    {
        $fetchChild = (int) $fetchChild;
        $apiUrl = 'https://qyapi.weixin.qq.com/cgi-bin/user/simplelist';

        $result = $this->_curl($apiUrl, [
            'access_token' => $this->getAccessToken(),
            'department_id' => $departmentId,
            'fetch_child' => $fetchChild,
            'status' => $status,
        ]);

        return json_decode($result, true);
    }

    /**
     * 获取部门列表.
     *
     * @param string $departmentId 部门ID
     *
     * @return array 获取到的数据
     */
    public function getDepartments($departmentId)
    {
        $apiUrl = 'https://qyapi.weixin.qq.com/cgi-bin/department/list';

        $result = $this->_curl($apiUrl, [
            'access_token' => $this->getAccessToken(),
            'id' => $departmentId,
        ]);

        return json_decode($result, true);
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
            . $this->getConfig('corpid').'&redirect_uri='
            . urlencode($uri)."&response_type=code&scope=snsapi_base&state={$state}#wechat_redirect";
    }

    /**
     * 由 OAth 认证的 code 获取 userid.
     *
     * @param string $code
     *
     * @return string|null
     */
    public function getUserId($code)
    {
        $apiUrl = 'https://qyapi.weixin.qq.com/cgi-bin/user/getuserinfo';

        $result = json_decode($this->_curl($apiUrl, [
            'access_token' => $this->getAccessToken(),
            'code' => $code,
        ]), true);

        if (isset($result['UserId'])) {
            return $result['UserId'];
        }

        return null;
    }

    /**
     * 获取某个用户的信息.
     *
     * @param string $userid 腾讯关联的userid
     *
     * @return array
     */
    public function getUserInfo($userid)
    {
        $apiUrl = 'https://qyapi.weixin.qq.com/cgi-bin/user/get';

        $result = json_decode($this->_curl($apiUrl, [
            'access_token' => $this->getAccessToken(),
            'userid' => $userid,
        ]), true);

        return $result;
    }

    /**
     * 删除某个用户.
     *
     * @param string $userid 腾讯关联的userid
     *
     * @return array
     */
    public function deleteUser($userid)
    {
        $apiUrl = 'https://qyapi.weixin.qq.com/cgi-bin/user/delete';

        $result = json_decode($this->_curl($apiUrl, [
            'access_token' => $this->getAccessToken(),
            'userid' => $userid,
        ]), true);

        return $result;
    }

    /**
     * 简化的 HTTP 通信函数.
     *
     * @param string       $url    目标 URL
     * @param array|string $data   发送的数据
     * @param string       $mothod 发送方式
     *
     * @return string 获得的内容
     */
    private function _curl($url, $data = '', $mothod = 'get')
    {
        $parStr = '';
        if (is_array($data)) {
            $parStr = http_build_query($data);
        } else {
            $parStr = $data;
        }

        if (strtolower($mothod) == 'get') {
            $result = file_get_contents(rtrim($url, '?').'?'.$parStr);
        } else {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $parStr);
            $result = curl_exec($ch);
            curl_close($ch);
        }

        return $result;
    }

    private function _parseUserList($userList)
    {
        if (is_array($userList['touser'])) {
            $userList['touser'] = implode('|', $userList['touser']);
        }

        return $userList;
    }
}
