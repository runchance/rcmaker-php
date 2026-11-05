# Local Captchas

[简体中文](README.md) | English

Two switchable local providers require no cloud account, external verification service or CDN. `image` is the default image captcha; `slider` is an offline jigsaw. Requests that do not use captchas do not load image engines.

> Desktop mouse and 360-pixel emulated-touch flows are verified against the Linux backend: failed drags, independent refresh, successful proofs, business submission and replay rejection. Emulation is not physical-device testing. Local sliders are not cloud risk controls; validate application browser compatibility and accessible alternatives.

## 1. Configuration

Select a provider in `config/captcha.php`:

```php
return [
    'default' => [
        'provider' => 'slider', // Use image to switch back to a text image.
        'expire' => 120,
        'token_expire' => 60,
        'width' => 280,
        'height' => 160,
        'tolerance' => 3,
        'rate_limit' => ['window' => 60, 'max_requests' => 30],
        // Supply owned production images; public_path() supports source and PHAR.
        // 'backgrounds' => [public_path() . '/captcha/background.jpg'],
    ],
];
```

Existing connections without `provider` retain `image`; `captcha()`, `captchaCheck()`, `C()` and `CC()` remain. Image length/alphabet/build/storage options are unchanged. Sliders require framework cache, active server verification/rate limits and single-use proofs; native Session/closure stores and frontend-only/reusable-proof modes are not accepted.

GD and the selected formats are required; text images additionally need FreeType. Bundled slider backgrounds are JPEG and masks PNG, so defaults need GD JPEG/PNG. Owned PNG backgrounds are also supported. No additional Composer installation is required. The bundled stable engine is pristine; external engine support is pinned to tested `zxf/captcha:1.1.0`.

## 2. Three Endpoints

This example uses the `index` application. Save the controller as `apps/index/controller/captcha.php`:

```php
namespace app\index\controller;

/** Serve local captchas and widget assets. */
class captcha
{
    /** GET: return an image or a structured slider challenge, according to provider. */
    public function data($req)
    {
        return $req->captcha('login');
    }

    /** POST: check one drag and issue the token required by the final business request. */
    public function verify($req)
    {
        return $req->json($req->captchaVerify('login', $req->post()));
    }

    /** GET: allow only widget.js / widget.css, never arbitrary file reads. */
    public function asset($req)
    {
        return $req->captchaAsset($req->get('file', ''));
    }
}
```

Append to existing `config/route.php`, preserving other routes:

```php
use RC\Route;
use app\index\controller\captcha;

Route::get('/captcha/data', [captcha::class, 'data']);
Route::post('/captcha/verify', [captcha::class, 'verify']);
Route::get('/captcha/assets', [captcha::class, 'asset']);
```

For multiple apps, replace the controller namespace and keep issuance, drag verification and business consumption in the same backend app. Static apps must not validate on the backend's behalf. Challenges bind app, connection, `login` purpose, client IP and User-Agent. They cannot cross those boundaries.

The slider defaults to the actual socket peer from `remoteIp()`, not untrusted forwarding headers. Users behind a reverse proxy share that peer's quota by default. Only after ensuring that the ingress proxy overwrites `X-Real-IP` and cannot be bypassed, add a trusted resolver to the existing slider connection. This example trusts **loopback proxies only**:

```php
'client_ip' => static function ($req): string {
    $peer = $req->remoteIp();
    return in_array($peer, ['127.0.0.1', '::1'], true)
        ? (string) $req->header('x-real-ip', '')
        : (string) $peer;
},
```

Explicitly allow any other proxy peers for your deployment; never read `ip()` or arbitrary forwarded headers unconditionally. The resolver runs once per stage and must return one valid IP. Empty values, address lists and exceptions fail closed. Image providers retain their existing IP compatibility behavior; this is a slider-only option.

## 3. Frontend

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

The same widget recognizes image/slider responses, so changing the provider does not replace the frontend library. It inserts a `captcha` hidden field containing the typed image answer or server-issued slider proof. Plain `return=text` is not a public widget mode.

For JSON business requests, read `captcha.getValue()`. Call `captcha.reset()` after failed business submissions/consumed proofs. Call `captcha.destroy()` when removing an SPA widget. Optional callbacks are `onSuccess(token)` and `onChange(value)`. Use independent instances per container and destroy old instances before remounting. Cross-origin APIs additionally need application CORS configuration; explicitly use `credentials: 'include'` for cookies, never wildcard authorization of arbitrary origins.

## 4. Business Verification Is Mandatory

At the beginning of the actual login/register handler, use exactly the same purpose and connection:

```php
if (!$req->captchaCheck('login', $req->post('captcha'))) {
    return $req->json(['code' => 400, 'message' => '验证码无效，请刷新重试']);
}

// Continue business authentication; a captcha is not login success or JWT/permission verification.
```

Slider flow: issue challenge, verify coordinates/track on the server, receive a proof, consume it in the business handler. Booleans, frontend success flags and challenge IDs cannot replace the last step. A failed drag consumes its challenge; final proofs are also single-use. Never cache successful validation to authorize later requests.

## Deployment And Limits

| Item | Behavior |
| --- | --- |
| Cache | Framework default or explicit framework store. File works for Windows development; shared Redis is recommended for Linux traffic. All workers/hosts must share the appropriate store. |
| Rate limit | Fixed window shared by app/connection/validated IP; issuance, drag checks and final consumption all count. Changing the purpose does not reset it. Shared NAT/proxy clients share this allowance; configure it for the application. |
| Lifetime | Challenges: 30–600 seconds; final proofs: 30–300. One coordinate attempt per challenge. Refresh creates another ID; older IDs expire independently without global scans. |
| Options | Width 200–600, height 120–400, tolerance 0–5 pixels. Backgrounds must be readable local/PHAR files, never remote URLs. |
| Packaging | Engines, JS/CSS, backgrounds and pieces read from PHAR without copying public beside the program; runtime cache remains external. Encrypted builds retain one precisely verified public upstream core in plaintext while protecting application PHP and RC adapters. Do not encrypt that core manually or remove its hash check. |
| Static apps | Frontend may run in an independently bound/preloaded static process group. Captcha APIs and business verification remain in the backend app. |
| Interaction | Slider uses pointer dragging. Choose an appropriate alternative for keyboard/other accessibility requirements rather than relying solely on the slider. |

**An offline slider is not cloud risk analysis and does not guarantee resistance to computer vision/AI.** Bundled backgrounds are demonstration material. Use a sufficiently varied owned image pool and application rate limits, JWT/permissions and monitoring. Client-supplied tracks are not trusted proof of humanity. Use HTTPS and do not treat this as the sole security boundary.
