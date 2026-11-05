# 本地验证码

简体中文 | [English](README.en.md)

支持两种可切换的本地提供方，不需要第三方账号、云服务或 CDN。`image` 是默认图形验证码；`slider` 是离线拼图滑块。未使用验证码的请求不会加载图片引擎。

> 已验证桌面鼠标、360 像素触控模拟与 Linux 后端的完整流程：错误拖拽拒绝、独立刷新、成功凭证、业务提交及重放拒绝。触控模拟不等于真机测试；本地滑块也不是云端风控，仍需结合业务验证浏览器兼容性与无障碍替代方案。

## 1. 配置

在 `config/captcha.php` 的连接中选择提供方：

```php
return [
    'default' => [
        'provider' => 'slider', // 改为 image 可切回图形验证码。
        'expire' => 120,
        'token_expire' => 60,
        'width' => 280,
        'height' => 160,
        'tolerance' => 3,
        'rate_limit' => ['window' => 60, 'max_requests' => 30],
        // 生产建议换成自有图片；public_path() 同时支持源码与 PHAR。
        // 'backgrounds' => [public_path() . '/captcha/background.jpg'],
    ],
];
```

旧连接没有 `provider` 时仍使用 `image`，原 `captcha()`、`captchaCheck()`、`C()`、`CC()` 保留。图形连接的 `length`、`charset`、`phrase`、`store` 等配置继续生效。滑块只用框架缓存，不接受 Session/closure 存储、关闭限流、前端直接放行或重复使用凭证。

图片需要 GD 及对应格式支持；图形验证码另需 FreeType。滑块自带 JPEG 背景和 PNG 拼图，所以默认需要 GD JPEG/PNG；也可配置自己的 PNG 背景。无需 Composer 额外安装，内置的是原版稳定引擎；外部引擎只支持已经验证的 `zxf/captcha:1.1.0`。

## 2. 三个接口

以下以 `index` 应用为例。控制器放在 `apps/index/controller/captcha.php`：

```php
namespace app\index\controller;

/** 提供本地验证码与静态组件资源。 */
class captcha
{
    /** GET：图形提供方返回图片；滑块提供方返回挑战数据。 */
    public function data($req)
    {
        return $req->captcha('login');
    }

    /** POST：校验滑块的一次拖拽，返回业务提交要使用的一次性 token。 */
    public function verify($req)
    {
        return $req->json($req->captchaVerify('login', $req->post()));
    }

    /** GET：只允许 widget.js / widget.css，不允许任意文件读取。 */
    public function asset($req)
    {
        return $req->captchaAsset($req->get('file', ''));
    }
}
```

在已有 `config/route.php` 中添加路由，保留其他配置：

```php
use RC\Route;
use app\index\controller\captcha;

Route::get('/captcha/data', [captcha::class, 'data']);
Route::post('/captcha/verify', [captcha::class, 'verify']);
Route::get('/captcha/assets', [captcha::class, 'asset']);
```

多应用项目请替换控制器应用名，并把生成、拖拽校验和最终业务校验放在同一后端应用；不要让静态应用替后端验证。挑战还绑定连接名、`login` 用途、客户端 IP 和 User-Agent，不支持跨应用/用途转用。

滑块默认使用 `remoteIp()` 的实际连接地址，不直接信任客户端发来的转发头。反向代理后的用户默认共享代理 IP 配额。只有确认入口代理会覆盖 `X-Real-IP`、且后端不会被绕过时，才在现有滑块连接中增加可信解析器，例如**仅信任回环代理**：

```php
'client_ip' => static function ($req): string {
    $peer = $req->remoteIp();
    return in_array($peer, ['127.0.0.1', '::1'], true)
        ? (string) $req->header('x-real-ip', '')
        : (string) $peer;
},
```

其他代理地址必须由部署者明确列入信任范围，不能无条件读取 `ip()` 或任意转发头。解析器每个阶段调用一次，必须返回单个合法 IP；空值、多地址列表或异常会拒绝处理。图片提供方保留原来的 IP 取值兼容行为，不套用这个滑块专用选项。

## 3. 前端

```html
<link rel="stylesheet" href="/captcha/assets?file=widget.css">
<form method="post" action="/login">
    <div id="captcha"></div>
    <button type="submit">提交</button>
</form>
<script src="/captcha/assets?file=widget.js"></script>
<script>
const captcha = new RcmakerCaptcha('#captcha', {
    challengeUrl: '/captcha/data',
    verifyUrl: '/captcha/verify',
    inputName: 'captcha',
    theme: 'auto'
});
</script>
```

同一组件会识别图片或滑块响应，后端修改 `provider` 后无需更换前端库。它会插入名为 `captcha` 的隐藏字段；图形模式装入输入答案，滑块模式装入服务端签发的 token。纯文本 `return=text` 不用于这个公开组件。

JSON 业务请求用 `captcha.getValue()` 取值；业务提交失败、凭证已消费时调用 `captcha.reset()`。SPA 移除组件时调用 `captcha.destroy()`。可传 `onSuccess(token)`、`onChange(value)` 回调；每个容器要创建独立实例，重复挂载前先销毁旧实例。跨域 API 还需按应用需求配置 CORS；需要 Cookie 时显式设 `credentials: 'include'`，不要使用任意来源通配授权。

## 4. 业务端必须再校验

在实际登录、注册等业务方法开头执行，生成和校验的用途名/连接名必须一致：

```php
if (!$req->captchaCheck('login', $req->post('captcha'))) {
    return $req->json(['code' => 400, 'message' => '验证码无效，请刷新重试']);
}

// 通过后再执行业务鉴权；验证码不等于登录成功，也不替代 JWT/权限校验。
```

滑块流程为「获取挑战 → 服务端验证坐标/轨迹 → 获得 token → 业务端消费 token」。布尔值、前端成功标记、挑战 ID 都不能代替最后一步。一次拖拽失败后挑战即失效；最终 token 也只能消费一次，不能缓存“验证通过”来给后续请求放行。

## 部署与边界

| 项目 | 说明 |
| --- | --- |
| 缓存 | 使用框架默认缓存或显式传入的框架缓存。Windows 可用 File；Linux 高并发建议共享 Redis。多进程/多主机必须能访问同一存储。 |
| 限流 | 固定时间窗按应用、连接和已核对的 IP 共享，生成、拖拽校验、最终消费都计数；不因更换用途名重置。共享 NAT/代理用户会共享配额，需按业务调整。 |
| 生命周期 | 挑战 30–600 秒，业务 token 30–300 秒；每个挑战最多一次坐标尝试。刷新创建新 ID，旧挑战不被全局扫描删除，会按自己的期限失效。 |
| 配置范围 | 宽 200–600、高 120–400、容差 0–5 像素；背景只能是可读本地文件或 PHAR 内文件，不能传远程 URL。 |
| 打包 | 引擎、JS/CSS、背景与拼图从 PHAR 读取，不用在程序旁复制 public；运行缓存仍用外部目录。加密构建会保留一个精确校验的公开上游核心原文，业务 PHP 和 RC 适配层仍加密；不要手动加密该核心或删除其哈希校验。 |
| 静态应用 | 前端可在独立静态进程组运行并预加载；验证码接口仍由后端 APP 提供，业务端校验不放到静态进程中。 |
| 访问方式 | 滑块是指针拖拽交互；需要键盘/其他无障碍交互的场景应选择合适的替代验证方案，不应只依赖滑块。 |

**本地滑块不是云端风控，也不能保证抵御图像识别或 AI。** 默认背景是体验素材，生产应使用足量自有图片，并结合业务限流、JWT/权限和异常监测。轨迹是客户端提交的数据，不是可信的真人证明。API 需使用 HTTPS，不能把该组件当作唯一安全边界。
