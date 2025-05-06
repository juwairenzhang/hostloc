<?php
// 设置默认时区
date_default_timezone_set("PRC");
// 引入配置文件
$config = require __DIR__ . '/config.php';

// 初始换环境变量
init_env();
// 开始刷分
brush();

/**
 * 刷分（每天登录+访问别人空间）
 * @return void
 */
function brush()
{
    global $config;
    $accounts = $config['accounts'];
    // 判断是否为Github Actions环境
    $is_actions = (bool)getenv('LOC_ACCOUNTS');

    $err_accounts = [];
    foreach ($accounts as $key => $account) {
        if ($account['last_brush'] === date("Y-m-d")) {
            continue;
        }
        echo "----------------------------------------------------------\n";
        if (empty($account['cookie'])) {
            echo "Cookie 未配置，跳过账号（" . ($is_actions ? mb_substr($account['username'], 0, 1) . '***' : $account['username']) . "）\n";
            echo date("Y-m-d H:i:s\n");
            echo "----------------------------------------------------------\n";
            $err_accounts[] = $account;
            continue;
        }

        echo "使用 Cookie 登录（" . ($is_actions ? mb_substr($account['username'], 0, 1) . '***' : $account['username']) . "）\n";
        $data = get_info_with_cookie($account['cookie']);
        if (empty($data['username'])) {
            echo "Cookie 可能已失效，账号刷分失败（" . ($is_actions ? mb_substr($account['username'], 0, 1) . '***' : $account['username']) . "）\n";
            echo date("Y-m-d H:i:s\n");
            echo "----------------------------------------------------------\n";
            $err_accounts[] = $account;
            continue;
        }

        echo "登录成功（" . ($is_actions ? mb_substr($account['username'], 0, 1) . '***' : $account['username']) . "）\n";
        if (!$is_actions) {
            echo "初始信息（用户组:{$data['group']},金钱:{$data['money']},威望:{$data['prestige']},积分:{$data['point']}）\n";
        }
        echo "刷分中 ";
        for ($i = 31180; $i < 31210; $i++) {
            http_get(str_replace('*', $i, 'https://ssdforum.org/space-uid-*.html'), $account['cookie']);
            echo $i == 31209 ? "+ 完成\n" : "+";
            sleep(rand(5, 10));
        }
        $data = get_info_with_cookie($account['cookie']);
        if (!$is_actions) {
            echo "结束信息（用户组:{$data['group']},金钱:{$data['money']},威望:{$data['prestige']},积分:{$data['point']}）\n";
        }
        echo date("Y-m-d H:i:s\n");
        echo "----------------------------------------------------------\n";
        $accounts[$key]['last_brush'] = date("Y-m-d");
        sleep(rand(5, 30));
    }

    // 更新最后刷分日期
    $config['accounts'] = $accounts;
    $data = var_export($config, true);
    file_put_contents(__DIR__ . '/config.php', "<?php\nreturn $data;");

    // 发送当天刷分失败通知
    notice($err_accounts);
}

/**
 * 使用 Cookie 获取个人信息
 * @param string $cookie_str
 * @return array
 */
function get_info_with_cookie($cookie_str)
{
    $data = [];
    $html = http_get('https://ssdforum.org/home.php?mod=spacecp&ac=credit', $cookie_str);
    preg_match('/<a.*?title="访问我的空间">(.*)<\/a>/', $html, $matches);
    if (isset($matches[1])) {
        $data['username'] = $matches[1];
    } else {
        $data['username'] = '';
    }

    preg_match("/>用户组: (.*?)<\/a>/", $html, $matches);
    if (isset($matches[1])) {
        $data['group'] = $matches[1];
    } else {
        $data['group'] = '?';
    }

    preg_match("/金钱: <\/em>(\d+)/", $html, $matches);
    if (isset($matches[1])) {
        $data['money'] = $matches[1];
    } else {
        $data['money'] = '?';
    }

    preg_match("/威望: <\/em>(\d+)/", $html, $matches);
    if (isset($matches[1])) {
        $data['prestige'] = $matches[1];
    } else {
        $data['prestige'] = '?';
    }

    preg_match("/积分: (\d+)<\/a>/", $html, $matches);
    if (isset($matches[1])) {
        $data['point'] = $matches[1];
    } else {
        $data['point'] = '?';
    }

    return $data;
}

/**
 * GET请求
 * @param $url
 * @param string $custom_cookie
 * @return bool|string
 */
function http_get($url, $custom_cookie = '')
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_URL, $url);
    if (!empty($custom_cookie)) {
        curl_setopt($ch, CURLOPT_COOKIE, $custom_cookie);
    }
    curl_setopt($ch, CURLOPT_USERAGENT, 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.102 Safari/537.36');
    curl_setopt($ch, CURLOPT_REFERER, 'https://ssdforum.org/');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

/**
 * 初始化环境变量到配置文件
 * @return void
 */
function init_env()
{
    global $config;

    // 获取环境变量中的账号和 Cookie（格式user1@@@cookie1---user2@@@cookie2）
    $env_loc_accounts = getenv('LOC_ACCOUNTS');
    if ($env_loc_accounts) {
        $new_accounts = [];
        foreach (explode("---", $env_loc_accounts) as $env_account) {
            $account_parts = explode("@@@", $env_account);
            if (count($account_parts) === 2) {
                $username = $account_parts[0];
                $cookie = trim($account_parts[1]);
                $new_accounts[$username] = array(
                    'username' => $username,
                    'cookie' => $cookie,
                    'last_brush' => '',
                );
            } elseif (count($account_parts) === 1) { // 仅有用户名，没有 Cookie，跳过
                echo "警告：账号 '{$account_parts[0]}' 缺少 Cookie 配置，已跳过。\n";
            } else {
                echo "警告：账号配置 '{$env_account}' 格式不正确，已跳过。\n";
            }
        }
        // 更新和添加账户
        foreach ($config['accounts'] as $account) {
            if (isset($new_accounts[$account['username']])) {
                $new_accounts[$account['username']]['last_brush'] = $account['last_brush'];
            }
        }
        // 最新的账号和 Cookie
        $config['accounts'] = array_values($new_accounts);
    }

    // 获取环境变量中的TG推送Key
    $env_tg_push_key = getenv('TG_PUSH_KEY');
    if ($env_tg_push_key) {
        $config['tg_push_key'] = $env_tg_push_key;
    }
}

/**
 * 通知
 * @param $err_accounts
 * @return void
 */
function notice($err_accounts)
{
    global $config;
    $tg_push_key = $config['tg_push_key'];

    // 最后一次执行且有刷分失败的账号且TG推送Key不为空才推送
    if (date('G') < 18 || empty($err_accounts) || empty($tg_push_key)) {
        return;
    }
    $username = array_column($err_accounts, 'username');
    $title = 'Hostloc 刷分失败';
    $content = '账号（' . implode('，', $username) . '）刷分失败，请检查 Cookie 是否有效';
    $data = array(
        "key" => $tg_push_key,
        "text" => $title . "\n" . $content
    );
    // Telegram 通知
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://tg-bot.t04.net/push',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json'
        ),
    ));
    curl_exec($curl);
    curl_close($curl);
}
