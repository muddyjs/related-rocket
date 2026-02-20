# Related Rocket (RR)

基于 **DB 结果表兜底 + Redis Object Cache（IDs/HTML）主缓存 + 异步重算** 的 WordPress 相关文章插件。

> 当前仓库是可激活、可运行的实现骨架，核心链路（建表、缓存、渲染、异步、CLI）已打通，便于继续迭代算法与运维能力。

---

## 1. 安装

### 1.1 环境要求

- WordPress（建议 6.x）
- PHP >= 7.4
- MySQL / MariaDB
- 建议安装并启用 Redis Object Cache
- 可选：Action Scheduler（存在时异步优先使用）

### 1.2 安装步骤

1. 将插件目录放入：`wp-content/plugins/related-rocket`
2. WP 后台进入 **插件** 页面，启用 **Related Rocket**。
3. 启用时会自动建表：`{$wpdb->prefix}rr_related`。

---

## 2. 配置

当前版本采用代码常量默认值（位于 `related-rocket.php`）：

- `RR_DEFAULT_N=30`
- `RR_W_TAG=5`
- `RR_W_CAT=2`
- `RR_PER_TAG_FETCH=200`
- `RR_PER_CAT_FETCH=200`
- `RR_MAX_CANDIDATES=1200`
- `RR_TTL_IDS=86400`
- `RR_TTL_HTML=86400`
- `RR_TTL_NEG=300`
- `RR_TTL_LOCK=60`
- `RR_JITTER_RATIO=0.1`

> 如需自定义，可在后续迭代中增加后台设置页或 filter 方案。

---

## 3. 使用方式

### 3.1 Shortcode

```text
[rr_related n="30"]
```

### 3.2 Template Tag

```php
<?php echo rr_related_posts(); ?>
<?php echo rr_related_posts(get_the_ID(), 30); ?>
```

### 3.3 模板覆盖

优先加载主题模板：

- `{theme}/rr/related-list.php`

若不存在则使用插件模板：

- `templates/related-list.php`


### 3.4 替换旧的“相关文章”代码

在单篇文章模板（如 `single.php`）中，删除旧的随机 `WP_Query` 相关文章代码，改为：

```php
<?php
if (function_exists('rr_related_posts')) {
    echo rr_related_posts(get_the_ID(), 15);
}
?>
```

也可使用 shortcode：

```text
[rr_related n="15"]
```

---

## 4. 验收清单（建议）

### 4.1 激活验收

- 插件启用不报错
- 数据库出现 `*_rr_related` 表

可在 WP-CLI 或代码中运行（管理员环境）

- `rr_db_selfcheck()`：检查建表 + 测试写读 + 清理测试数据

### 4.2 功能验收

1. 文章页插入 `[rr_related]`，页面正常渲染（无 fatal）。
2. 修改文章 tags/cats 后会触发入队重算（去重生效）。
3. 异步完成后相关文章可命中缓存路径。

### 4.3 CLI 验收

- 批量预热：

```bash
wp rr warmup --batch=200 --enqueue=1
```

- 单篇同步重算（调试）：

```bash
wp rr rebuild <post_id> --sync=1
```

---

## 5. 性能测试建议

### 5.1 缓存命中测试

目标：命中 HTML cache 时插件自身几乎 0 SQL。

步骤：
1. 对同一文章连续请求两次。
2. 第一次可能走 DB/渲染；第二次应命中 HTML cache。

### 5.2 冷启动测试

目标：缓存未命中时可从 DB 兜底并快速恢复缓存。

步骤：
1. 删除该文章 `rel_html` / `rel_ids` 缓存。
2. 访问文章页，观察是否可回源 DB 并重建缓存。

### 5.3 批处理稳定性

目标：warmup 过程中不 OOM。

步骤：
1. 执行 `wp rr warmup --batch=200 --enqueue=1`
2. 观察内存与处理进度，确认批间清理生效（`stop_the_insanity/gc_collect_cycles`）。

---

## 6. 故障演练

### 6.1 Redis 不可用

预期：插件仍可通过 DB 表兜底获取结果（性能下降但功能可用）。

演练：
1. 临时停 Redis。
2. 访问文章页，确认无 fatal，内容可正常打开。

### 6.2 缓存击穿/批量失效

预期：
- negative caching + queued 去重降低风暴
- 单篇不会无限重复入队

演练：
1. 删除热点文章缓存（HTML/IDs）。
2. 并发访问该文章页。
3. 检查队列与缓存恢复情况。

### 6.3 Action Scheduler 缺失

预期：自动走 cron option 队列兜底。

演练：
1. 在无 Action Scheduler 环境触发重算。
2. 检查 `rr_async_queue` option 及 cron 消费情况。

---

## 7. 运维建议

- 建议配合 Nginx FastCGI Cache + Redis Object Cache。
- 大站优先使用 `warmup --enqueue=1` 进行离峰预热。
- 当模板或主题版本变化时，关注 HTML cache 命中变化。

---

## 8. 开发说明

核心文件：

- `related-rocket.php`：插件入口与常量
- `includes/class-rr-install.php`：建表
- `includes/class-rr-db.php`：结果表读写
- `includes/class-rr-cache.php`：缓存键与缓存封装
- `includes/class-rr-builder.php`：候选与打分
- `includes/class-rr-render.php`：前台渲染链路
- `includes/class-rr-async.php`：异步入队/执行
- `includes/class-rr-cli.php`：CLI 运维命令
- `templates/related-list.php`：默认模板
