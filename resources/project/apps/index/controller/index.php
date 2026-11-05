<?php

namespace app\index\controller;

/** 默认应用入口；访问 GET /。 */
class index
{
    /** 返回欢迎信息；JSON 接口请复用 json($request, $data)。 */
    public function index($request)
    {
        return 'Hello rcmaker!';
    }
}
