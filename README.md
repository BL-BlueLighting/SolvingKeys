# SolvingKeys

> 用于存放、共享与协作整理解谜线索的端到端加密平台。  
> By BL.BlueLighting

SolvingKeys 使用 PHP MVC 架构；Key 内容、钥匙库与项目聊天记录在浏览器端使用
AES-256-GCM 加密，服务器只保存密文。管理员可以管理用户与项目秩序，但无法读取
Keys 或聊天明文。

当前仓库是依据 `PROMPT_OF_GENERATING.md` 完成的初始实现（该提示词文件已加入
`.gitignore`，不进入版本库）。

---

## 功能

- **Keys**：独立的 64 位十六进制内容密钥，服务端只保存 AES-256 密文。
- **钥匙库（keys_key）**：`Keys.id → 内容密钥` 的加密映射，由用户自己的
  16 位十进制数字密码加密；支持云端 / 浏览器 localStorage 两种保存方式。
- **分享与轮换**：分享链接可携带 `#key=…` 片段；内容密钥可随时轮换。
- **项目**：聚合 Keys、成员与加密聊天；私密项目默认不可见，公开化需要全体成员同意。
- **邀请—接受制**：项目所有者邀请，用户接受后加入；成员可退出。
- **加密聊天**：复用项目内某个 Key 的内容密钥加密 `messaging_record`。
- **三种 Markdown 编辑器**：Vditor、Milkdown Crepe、BlockNote，可随时切换；
  聊天输入同样支持切换。
- **完整 LaTeX**：编辑器和最终渲染均支持 `$…$` / `$$…$$` 公式（KaTeX）。
- **特殊语法**：`@用户`、`&Key`、`&^行号^Key`。
- **通知**：@提及、项目邀请、公开化投票结果。
- **管理员前端**：用户搜索、封禁（只读）、级联删除；站点名称、Logo、描述、页脚文字与注册开关；无独立管理后台，也**无法解密内容**。
- **安全基线**：SM3+盐登录密码、CSRF、CSP、登录限流、Prepared Statements、HTML 转义。

---

## 架构

```
(root)
├── index.php                 公共入口
├── router.php                PHP 内置服务器路由脚本
├── config.php                默认配置
├── config.local.example.php  本地配置示例
├── Database.php              PDO + 自动迁移
├── User.php                  User 模型（SM3、权限、钥匙库）
├── Keys.php                  Keys 模型
├── Project.php               Project 模型（成员、邀请、投票、消息）
├── Notification.php          通知模型
├── Site.php                  站点信息设置模型
├── include/                  主控制器（路由、认证、Keys、项目、API、管理）
├── views/default/            默认主题视图
├── frontend/                 Vite 前端源码
├── assets/
│   ├── dist/                 构建产物（Vite manifest）
│   └── vendor/vditor/        Vditor 运行时资源（Lute、KaTeX、图标）
├── uploads/                  管理员上传的站点 Logo（默认不追踪）
├── data/                     SQLite 数据库与错误日志（默认不追踪）
└── .htaccess                 Apache 重写与访问保护
```

MVC 约定与文档一致：模型在根目录、主控制器在 `include/`、视图在
`views/default/`。项目**没有独立管理后台**，管理员功能以普通页面形式集成在前端。

---

## 安全模型

### 1. 登录密码

- 服务器生成 16 字节随机盐。
- 存储 `SM3(salt + ":" + password)`，校验时使用 `hash_equals`。
- 登录尝试按“用户名 + IP”限流（默认 15 分钟 6 次）。

### 2. Key 内容

- 创建 Key 时浏览器生成 32 字节随机密钥，表示为 **64 位十六进制**。
- 明文结构先加 6 字节头，再使用 **AES-256-GCM** 加密：
  - 内容头：`12 AB 25 EF 37 CD`
  - 消息头：`12 AB 26 EF 38 CD`
  - 钥匙库头：`12 AB 24 CD 36 EF`
- 密文信封：`"SKD1" || IV(12) || ciphertext+tag`，Base64 后存入数据库。
- 服务器永远不会收到内容密钥或内容明文。

### 3. 钥匙库（keys_key）

- 用户注册时手动设置 **16 位十进制数字密码**；该密码从不上传。
- 浏览器用 PBKDF2-SHA-256（默认 310,000 次迭代）派生 AES-256-GCM 密钥。
- 明文结构：6 字节头 + `SOLVINGKEYS / KEYS MANAGER / JSON / SOVLINGKEYS / KEYS MANAGER`。
  - 关闭标记 `SOVLINGKEYS` 保留规范原拼写。
- 解锁时会校验头与标记，并依赖 GCM 认证标签检测错误密码/损坏数据。
- 云端只保存密文信封；关闭云端后服务器立即清空 `keys_key`。
- 当前标签页会把“已解锁的钥匙库”缓存在 sessionStorage 中；退出登录或关闭标签页失效。
- **忘记数字密码 = 无法解密钥匙库**；单个 Key 的 64 位内容密钥可另行备份。

### 4. 管理员

- 管理员只能搜索、封禁（设为只读）、删除用户。
- 管理员与普通登录用户一样只能拿到密文；没有内容密钥无法解密。
- 管理员账号之间不能互相封禁/删除；第一个注册用户自动成为管理员。

### 5. 分享链接

分享链接形如：

```
https://example.com/keys/12#key=<64位内容密钥>
```

`#key=…` 片段不会发送到服务器，但会存在于浏览器历史/转发记录中。请只分享给信任的人。
页面解密成功后会自动从地址栏移除该片段。

### 6. 传输与浏览器要求

- WebCrypto 需要 **HTTPS 或 localhost** 安全上下文。
- 默认 CSP 禁止内联脚本与外部脚本；图片允许 `https:` 头像。
- 所有表单使用 CSRF Token；所有 API 写操作校验 `X-CSRF-Token`。

---

## 快速开始

### 环境要求

- PHP **8.2+**（推荐 8.3+）
- 扩展：`pdo`、`pdo_sqlite` 或 `pdo_mysql`、`mbstring`、`openssl`、`json`、`fileinfo`（Logo 上传校验）
- Node.js **20+**（仅在需要重新构建前端时）

### SQLite（开箱即用）

```bash
# 1. 确保数据与上传目录可写
chmod -R u+rwX data uploads

# 2. 可选：如果仓库未包含构建产物
cd frontend
npm install
npm run build
cd ..

# 3. 启动内置服务器（WebCrypto 在 localhost 可用）
php -S 127.0.0.1:8000 router.php
```

打开 <http://127.0.0.1:8000>，注册第一个用户。第一个注册用户会自动成为管理员。

数据库文件默认位于 `data/solvingkeys.sqlite`，首次访问时自动建表。

### MySQL / MariaDB

```bash
cp config.local.example.php config.local.php
```

编辑 `config.local.php`：

```php
return [
    'db' => [
        'driver' => 'mysql',
        'mysql' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'solvingkeys',
            'username' => 'solvingkeys',
            'password' => 'change-me',
            'charset' => 'utf8mb4',
        ],
    ],
];
```

创建数据库后直接访问应用，自动迁移会创建表。`config.local.php` 已被 git 忽略。

### Apache

- 启用 `mod_rewrite`；
- 允许项目根目录读取 `.htaccess`（`AllowOverride All`）；
- 将站点根目录指向项目根（`index.php` 所在目录）；
- 内置 `.htaccess` 已拒绝 `include/`、`views/`、`frontend/`、`data/` 与模型/配置文件。

如果应用部署在子路径（例如 `/solvingkeys`），可在 `config.local.php` 中设置
`app.base_path => '/solvingkeys'`。

### Nginx（示例）

```nginx
server {
    listen 443 ssl;
    server_name example.com;
    root /var/www/SolvingKeys;
    index index.php;

    # 静态资源
    location /assets/ {
        try_files $uri =404;
        expires 7d;
    }

    # 拒绝内部源码与数据
    location ~ ^/(include|views|frontend|data|vendor|node_modules)/ { deny all; }
    location ~ ^/(Database|User|Keys|Project|Notification|Site|config|config\.local|router)\.php$ { deny all; }
    location ~* \.(sqlite|sqlite3|db|log)$ { deny all; }

    # 上传目录只允许图片，禁止执行脚本
    location ~ ^/uploads/.*\.(php[0-9]*|phtml|phar|cgi|pl|py|sh|js|html?)$ { deny all; }
    location /uploads/ {
        try_files $uri =404;
        expires 7d;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
```

> Nginx 不会读取 `.htaccess`，上面的 `deny` 规则必须保留。

---

## 使用流程

### 创建与阅读 Key

1. “我的 Keys → 新建 Key”。
2. 浏览器生成 64 位十六进制内容密钥；**只显示一次**。
3. 立即复制/下载密钥，并选择是否保存到加密钥匙库。
4. 编辑内容后“保存并加密”；服务器只收到密文。
5. 打开 Key 时：
   - 本标签页已记住密钥 → 直接解密；
   - 钥匙库已解锁且包含该密钥 → 直接解密；
   - 否则输入 64 位密钥，或先解锁钥匙库。
6. 首次用密钥解密后可将它保存进钥匙库；以后无需再次输入。

### 分享与轮换

- “分享”会展示内容密钥和带 `#key=…` 的链接。
- 接收者登录后打开链接即可解密；成功后会从地址栏清除片段。
- “轮换密钥”会生成新密钥并重新加密内容；旧密钥立即失效。
- 若该 Key 正被项目用作消息密钥，轮换前需要先处理对应项目聊天。

### 项目与聊天

1. 创建项目（默认私密）。
2. 项目所有者按用户名邀请成员；成员接受后加入。
3. 成员可将**自己拥有的 Key** 加入项目。
4. 所有者选择项目内某个 Key 作为“消息密钥”：
   - 浏览器用该 Key 的内容密钥初始化/重新加密 `messaging_record`；
   - 成员首次进入聊天时输入该 Key 的内容密钥（或从钥匙库取出）。
5. 发送消息时浏览器解密当前消息数组、追加消息、再加密上传；
   使用版本号做乐观并发控制，冲突时自动合并重试。
6. 更换消息密钥时，需同时拥有旧密钥与新密钥，以重新加密历史消息。
   如果旧密钥确实已经丢失，所有者可在“聊天设置”勾选“放弃历史消息并重新开始”，用新密钥初始化空记录。
7. 公开化：所有者发起投票，全体成员逐一同意后项目公开；任一成员拒绝则保持私密。
8. 成员退出时，其拥有的 Key 会从项目移除；若移除的是消息密钥，项目聊天会停用并在服务器端清除消息记录（请提前更换消息密钥）。

### Markdown 特殊语法

| 语法 | 作用 |
| --- | --- |
| `@123` / `@用户名` | @ 提及用户并产生通知。`@123` 按用户 ID 解析。 |
| `$$E=mc^2$$` | LaTeX 公式（最终渲染使用 KaTeX）。 |
| `$x^2$` | 行内公式（编辑器与渲染均支持）。 |
| `&12` / `&Key名称` | 引用一个 Key；按 ID 或名称解析为链接。 |
| `&^5^12` | 引用 Key #12 的第 5 行；链接到 `/keys/12?line=5`。 |

- 行号规则：按段落顺序拼接各段 `Content`，段落之间计一个空行，行号从 1 开始。
- Key 名称含空格时请使用 ID 引用（`&12`）。
- `@` / `&` / `$` 位于代码块、行内代码与公式内部时不会被解析。

### 编辑器

Keys 编辑页与聊天输入都提供：

- **Vditor**（即时渲染，默认，启动最快）
- **Milkdown Crepe**（ProseMirror 结构化编辑）
- **BlockNote**（块编辑，Notion 风格）

选择会记住在 `localStorage`；切换时内容以 Markdown 同步。复杂 Markdown 经
BlockNote 等块编辑器往返时可能有轻微格式归一化。

### 管理员

- “用户管理”页面支持搜索、设为只读/解除、删除。
- “站点设置”页面支持：
  - 站点名称（导航栏、标题、登录页、页脚）；
  - Logo URL（仅 HTTPS 或站内路径）或上传 PNG/JPEG/WebP/GIF（最大 2 MB，保存到 `uploads/site/`）；
  - 站点描述（登录页与 meta description）；
  - 页脚文字；
  - 开放/关闭注册。
- 上传目录已限制为图片扩展名；Apache/Nginx/内置服务器均拒绝执行上传目录中的脚本。
- 只读用户仍可登录、阅读、解锁内容，但不能创建或修改 Keys/项目/消息。
- 删除用户：
  - 删除其拥有的 Keys；
  - 其拥有的项目若有其他成员，转移给最早加入的成员，否则删除；
  - 从其他项目移除该成员及其 Keys；
  - 清理邀请、投票、通知。
- 管理员账号受保护，不能互相删除/封禁。

---

## 数据模型摘要

| 表 | 说明 |
| --- | --- |
| `users` | 用户、SM3 密码、头像、权限、`keys_key`、封禁、云端开关 |
| `keys` | `key_name`、`owner`、AES-256 密文 `content`、`version` |
| `projects` | 名称、`project_keys` JSON、成员 JSON、可见性、`messaging_record`、`messaging_key_id`、`messaging_version` |
| `notifications` | 用户通知 |
| `project_invitations` | 邀请—接受状态 |
| `project_visibility_requests` / `project_visibility_ballots` | 公开化投票 |
| `login_attempts` | 登录限流记录 |
| `site_settings` | 管理员可编辑的站点名称、Logo、描述、页脚与注册开关 |

文档中的字段全部保留；另加了 `username_norm`、`email_norm`（大小写不敏感唯一）、
`cloud_vault`、`version`、`messaging_key_id`、`messaging_version` 等实现字段。

---

## 存档格式

### 头字节

| 类型 | 字节 |
| --- | --- |
| `keys_key` | `12 AB 24 CD 36 EF` |
| `Keys.content` | `12 AB 25 EF 37 CD` |
| `Project.messaging_record` | `12 AB 26 EF 38 CD` |

### `keys_key` 明文

```
<6 字节头>
SOLVINGKEYS
KEYS MANAGER

{
    "(Keys.id)": "(内容密钥)"
}

SOVLINGKEYS
KEYS MANAGER
```

### `Keys.content` 明文

```
<6 字节头>
SOLVINGKEYS
KEYS CONTENT

[
    {
        "Title": "...",
        "Content": "(markdown)"
    }
]
```

### `Project.messaging_record` 明文

```
<6 字节头>
SOLVINGKEYS
MESSAGING CONTENT

[
    {
        "From": 1,
        "Content": "(markdown)"
    }
]
```

消息记录格式未包含时间戳；顺序即发送顺序。

### 密文信封

- 数据（Key/消息）：`Base64("SKD1" || IV || AES-256-GCM(明文))`
- 钥匙库：`Base64("SKV1" || salt(16) || iterations(uint32 BE) || IV || AES-256-GCM(明文))`

---

## 前端构建

构建产物已放在 `assets/dist/`，Vditor 运行时资源在 `assets/vendor/vditor/`。

重新构建：

```bash
cd frontend
npm install
npm run build
```

`npm run build` 会：

1. 使用 Vite 生成多入口 bundle 与 manifest；
2. 把 `vditor/dist` 复制到 `assets/vendor/vditor/dist`。

PHP 通过 `assets/dist/.vite/manifest.json` 自动加载对应 JS/CSS。

---

## 已确认的设计决策

依据实现前的确认：

1. **Key 可见性**：任何已登录用户凭链接可访问 Key 名称与密文；内容仍需 64 位密钥解密。
2. **项目消息密钥**：复用项目内某个 Key 的内容密钥；成员手动导入并存入钥匙库。
3. **封禁行为**：用户可登录但只读，不能创建或修改 Keys、项目与消息。
4. **成员加入**：邀请—接受制；公开化需要全体成员同意。

其它实现约定：

- 数据库默认 SQLite，可通过 `config.local.php` 切换 MySQL。
- 界面使用 Bootstrap 5，中文文案。
- 数字密码固定为 16 位十进制数字（ASCII 16 字节）。
- 关闭云端钥匙库时，服务器上的 `keys_key` 立即清空；本地副本由浏览器保存。
- 删除 Key 时若该 Key 正作为项目消息密钥，则拒绝删除，需先更换消息密钥。
- 消息采用版本号乐观锁；发送冲突时客户端自动合并并重试。

---

## 已知限制

- 没有邮箱验证、找回密码或自助重置登录密码。
- 没有 WebSocket 实时推送；聊天每 8 秒轮询一次。
- Key 名称是明文元数据；知道 Key ID 的登录用户可以看到名称与密文。
- 分享链接包含明文内容密钥；任何获得链接的人都能解密。
- 项目消息格式按规范不含时间戳。
- Key 名称含空格时不能使用 `&名称` 引用，请使用 ID。
- 多个 Key 重名时，按名称引用可能产生歧义；请优先使用数字 ID。
- 管理员删除用户是破坏性操作；删除前请确认。
- 关闭标记 `SOVLINGKEYS` 保留了原始规范中的拼写。
- 行号引用只覆盖段落 `Content` 的拼接行；段落之间的空行分隔符也计入行号。
- 分享链接必须由**已登录用户**打开；未登录时服务器会跳转到登录页，浏览器可能丢失 `#key=…` 片段。请先登录再打开分享链接。
- QQ 头像服务只接受 40 / 100 / 640 等离散尺寸；系统会自动选择受支持的尺寸并用 CSS 缩放。外部头像加载失败时会回退到本地 `assets/default-avatar.svg`；自定义头像外链仅允许 HTTPS。

---

## 验证清单（`test` 阶段使用）

本次实现尚未运行应用级测试；收到 `test` 指令后可按以下顺序检查：

1. 首次注册 → 自动管理员 → 登录/退出。
2. 创建 Key → 保存密钥 → 编辑（三种编辑器切换）→ 保存 → 重新打开自动解密。
3. 错误密钥、轮换密钥、分享链接、从钥匙库移除。
4. 钥匙库云端/本地切换、修改 16 位数字密码、重新登录解锁。
5. 创建项目 → 邀请第二个账号 → 接受 → 加入 Key → 设置消息密钥 → 双方聊天。
6. @提及、通知、可见性投票、成员退出、消息密钥更换。
7. 管理员封禁（只读）与删除用户。
8. 管理员站点设置：名称、Logo 上传/URL、描述、页脚、注册开关；确认页面即时生效。
9. MySQL 模式、Apache/Nginx 部署与 CSP 检查。

---

## License

Copyright (C) 2026 BL.BlueLighting

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, version 3 of the License.

This program is distributed in the hope that it will be useful, but WITHOUT
ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program. If not, see <https://www.gnu.org/licenses/>.

The full license text is available in [LICENSE](LICENSE).

---

## 作者

BL.BlueLighting
