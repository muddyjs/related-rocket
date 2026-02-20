# Related Rocket (RR) 技术方案文档（给 CODEX 实施）

> 目标：基于 **DB 结果表兜底 + Redis Object Cache（IDs/HTML）主缓存 + 异步重算** 的架构，实现高性能 WordPress 相关文章插件。  
> 约束：**相关性仅 Tag/Category 权重匹配**；展示 **标题 + 缩略图 + Tags**；规模：5 万 post、10 万 IP/日；环境：Nginx FastCGI Cache + Redis Object Cache。

---

## 1. 背景与目标

### 1.1 场景
- WordPress 内容站
- `post` 数量：约 50,000
- 日 UV：约 100,000 IP
- 服务器：已启用 **Nginx FastCGI Cache**，已安装 **Redis Object Cache**（WP Object Cache 走 Redis）

### 1.2 插件目标
- 在文章页输出“相关文章”列表
- 相关性策略：**仅 Tag/Category 权重匹配**
- 展示字段：**标题 + 缩略图 + Tags（每篇最多 3 个 tag）**
- 性能目标：前台渲染尽量 **0 SQL 或极少 SQL**，避免实时计算；计算移至离线异步。

### 1.3 设计原则
- **前台不做相关性计算**，最多做：读缓存 → 读 DB 兜底 → 批量取文章 → 渲染 → 写缓存
- **DB 结果表作为权威兜底**，Redis object cache 作为热缓存（IDs/HTML）
- **异步重算**，避免 save_post 卡顿、避免高峰期 CPU/DB 抖动
- **防击穿**：negative caching + single-flight 锁 + 任务去重
- Builder 必须避免 N+1（term cache 预热）

---

## 2. 关键参数（默认值，可配置）

| 参数 | 默认 | 说明 |
|---|---:|---|
| N | 30 | 每篇文章展示的相关文章数量 |
| w_tag | 5 | 标签权重 |
| w_cat | 2 | 分类权重 |
| per_tag_fetch | 200 | 每个 tag 拉候选数上限 |
| per_cat_fetch | 200 | 每个 cat 拉候选数上限 |
| max_candidates | 1200 | 候选池总上限（强制裁剪） |
| tag_display_limit | 3 | 每条文章展示 tags 数上限 |
| thumb_size | medium | 缩略图尺寸 |
| ttl_ids | 86400 | IDs 缓存 TTL |
| ttl_html | 86400 | HTML 缓存 TTL |
| ttl_neg | 300 | 空结果缓存 TTL（negative caching） |
| ttl_lock | 60 | single-flight 锁 TTL |
| jitter_ratio | 0.1 | TTL 抖动比例（0~10%） |
| algo_ver | 1 | 算法版本（写 DB/缓存 key） |
| tpl_ver | 1 | 模板版本（HTML cache key） |

> TTL jitter：实际 TTL = base + rand(0, base*jitter_ratio)

---

## 3. 数据库设计（权威兜底层）

### 3.1 表名
`{$wpdb->prefix}rr_related`

### 3.2 表结构（dbDelta 兼容）
- `post_id BIGINT UNSIGNED NOT NULL`（PRIMARY KEY）
- `related_json TEXT NOT NULL`  
  - 存储格式：`[[id,score],[id,score],...]`（节省空间，解析简单）
- `algo_ver SMALLINT UNSIGNED NOT NULL DEFAULT 1`
- `updated_at DATETIME NOT NULL`
- `source_hash BINARY(16) NOT NULL`  
  - `md5("t:...|c:...")` 的 binary，用于判断是否需要重算（可选优化）

索引：
- PRIMARY KEY (`post_id`)
- KEY `updated_at` (`updated_at`)
- 可选：KEY `algo_updated` (`algo_ver`,`updated_at`)（仅当需要按版本/时间扫描）

### 3.3 建表 SQL（模板）
```sql
CREATE TABLE wp_rr_related (
  post_id BIGINT UNSIGNED NOT NULL,
  related_json TEXT NOT NULL,
  algo_ver SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL,
  source_hash BINARY(16) NOT NULL,
  PRIMARY KEY (post_id),
  KEY updated_at (updated_at),
  KEY algo_updated (algo_ver, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.4 写入策略
- 使用 `REPLACE INTO` 覆盖写入（每篇文章一行）
- `updated_at = current_time('mysql')`
- `source_hash = UNHEX(md5hex)`

---

## 4. 缓存设计（Redis Object Cache via wp_cache_*）

统一 group：`rr`

### 4.1 缓存 Key 规范

**IDs 缓存**
- key：`rel_ids:{post_id}:v{algo_ver}:n{N}`
- value：`int[]`
- TTL：`ttl_ids + jitter`

**HTML 缓存（主路径）**
- key：`rel_html:{post_id}:v{algo_ver}:n{N}:tpl{tpl_ver}:th{theme_hash}`
- value：HTML string
- TTL：`ttl_html + jitter`

**Negative caching（空结果缓存）**
- key：`rel_neg:{post_id}:v{algo_ver}:n{N}`
- value：`1`（或 `'empty'`）
- TTL：`ttl_neg`（短 TTL，不加 jitter 也可）

**Single-flight 锁（防多请求同时重建）**
- key：`rel_lock:{post_id}:v{algo_ver}:n{N}`
- value：`1`
- TTL：`ttl_lock`（60 秒）

**Queue 去重（跨请求）**
- key：`queued:{post_id}:v{algo_ver}`
- value：`1`
- TTL：`300`（5 分钟）

### 4.2 theme_hash 生成
- `theme_hash = substr(md5(theme_name.'|'.theme_version), 0, 8)`

### 4.3 Runtime cache（单请求内 static memo）
- 同一请求中同一个 `(post_id,n,theme_hash)` 被多次调用时，直接返回内存结果，避免重复 Redis IO。

---

## 5. 前台输出（Render Path，热路径）

### 5.1 接口（必须实现）
- Shortcode：`[rr_related n="30"]`
- Template tag：`rr_related_posts($post_id=null, $n=null): string`

### 5.2 前台流程（严格按此顺序）
给定 `(post_id,n)`：

1. **Runtime cache** 命中 → return  
2. **HTML cache** 命中 → return  
3. 命中 **negative cache**（空结果）→ return `''`  
4. 命中 **IDs cache**：批量取 posts → render → set HTML → return  
5. **DB 兜底**：`SELECT related_json FROM rr_related WHERE post_id=%d LIMIT 1`  
   - 有记录：解析为 ids → set IDs → render → set HTML → return  
6. DB 无记录：  
   - set negative cache（短 TTL）  
   - enqueue rebuild（异步）  
   - return `''`（或可选 fallback：同分类最新 N 条）

### 5.3 防击穿：single-flight 重建锁
当需要渲染并写 HTML（IDs/DB 命中）或 DB 无记录要触发 rebuild 时：
- 尝试 `wp_cache_add(lock_key,1,'rr',ttl_lock)`
  - **成功**：当前请求负责渲染/写缓存（或 enqueue rebuild）
  - **失败**：说明其他请求正在重建 → 直接走 DB 兜底/返回空（依赖 NEG），不重复 enqueue

### 5.4 渲染要求
- 使用一次 `WP_Query` 批量取回相关 posts，保持顺序：
  - `post__in => $ids`
  - `orderby => 'post__in'`
  - `posts_per_page => n`
- 渲染阶段建议开启 cache 以减少 meta/term 查询：
  - `update_post_meta_cache => true`
  - `update_post_term_cache => true`
- 输出字段：
  - 标题（link）
  - 缩略图（thumb_size，`loading="lazy" decoding="async"`）
  - tags（最多 tag_display_limit 个）

### 5.5 模板覆盖策略
- 优先主题覆盖：`{theme}/rr/related-list.php`
- 否则使用插件：`templates/related-list.php`
- 模板路径可用 `static` 缓存（进程内），减少文件 IO。

---

## 6. Builder（异步计算层）

### 6.1 输入输出
输入：`post_id`  
输出：`[[id,score],...]` 长度 ≤ N

### 6.2 候选池生成（强制限制规模）
步骤：
1. 读取源文章 tag_ids / cat_ids（排序）
2. 候选收集：
   - 对每个 tag_id：取最新 `per_tag_fetch` 篇（仅 ids）
   - 不足再对每个 cat_id：取最新 `per_cat_fetch` 篇
3. 去重、移除自身、裁剪至 `max_candidates`
4. 裁剪排序依据：**按 post_date DESC**

候选查询（Builder 阶段）必须使用轻量参数：
- `fields => 'ids'`
- `no_found_rows => true`
- `update_post_meta_cache => false`
- `update_post_term_cache => false`
- `ignore_sticky_posts => true`
- `post_status => 'publish'`

### 6.3 term cache 预热（必须）
对候选 IDs：
- 调用 `update_object_term_cache($candidate_ids, 'post')`  
避免循环 `get_the_terms` 触发 N+1。

### 6.4 打分
对每个候选：
- `common_tag_count = |tags(source) ∩ tags(candidate)|`
- `common_cat_count = |cats(source) ∩ cats(candidate)|`
- `score = common_tag_count*w_tag + common_cat_count*w_cat`
- `score == 0` 可丢弃（可配置）

排序：
- `score DESC`
- tie-break：`post_date DESC`

取 TopN。

### 6.5 source_hash（可选跳过重算）
- `payload = 't:' + join(',',tag_ids_sorted) + '|c:' + join(',',cat_ids_sorted)`
- `md5hex = md5(payload)`
- 若 DB `source_hash` 一致且 `updated_at` 足够新，可跳过重算。

---

## 7. 异步层（Async / Queue）

### 7.1 首选：Action Scheduler
若检测到 `as_enqueue_async_action` 存在：
- enqueue：`as_enqueue_async_action('rr_rebuild_one', ['post_id'=>$id], 'rr');`
- handler：`add_action('rr_rebuild_one', 'RR_Async::handle_rebuild_one');`

### 7.2 兜底：WP-Cron
若不存在 Action Scheduler：
- 建立 cron 事件（建议每分钟）
- 队列存 option（数组）+ 去重 set（option 或 transient）
- 每次处理 batch（默认 20）

### 7.3 入队触发 hooks（必须）
仅对 `post_type=post` 且最终状态为 `publish`：
- `save_post`（排除 autosave/revision）
- `set_object_terms`（taxonomy 为 `post_tag` / `category`）
- `transition_post_status`（涉及 publish 状态变化）

### 7.4 钩子重入去重（必须：单请求+跨请求）
**单请求去重**
- static `$seen[$post_id]=true`，重复触发直接 return

**跨请求去重（关键）**
- `wp_cache_add("queued:$post_id:v$algo_ver", 1, 'rr', 300)` 成功才 enqueue

### 7.5 重算流程（异步 handler）
对 `post_id`：
1. 校验文章存在且 publish
2. 执行 Builder 得到 `[[id,score]]`
3. 写 DB（REPLACE）
4. 删除缓存：IDs、HTML、NEG（必要时 lock 让其自然过期）
5. 可选：立即预生成 HTML 并写缓存（减少冷启动抖动）

---

## 8. WP-CLI（建议实现，用于预热与运维）

### 8.1 命令
- `wp rr rebuild <post_id> [--n=30] [--sync=1]`
- `wp rr warmup [--batch=200] [--enqueue=1] [--sync=0]`

### 8.2 warmup 策略（5 万篇）
推荐只 enqueue，不同步重算：
- 每批取 200 个 ID（可调）
- 每批：
  - enqueue rebuild（依赖去重）
  - 清理批次变量、gc
  - 定期调用 `stop_the_insanity()` 或等价清理函数，避免 OOM

---

## 9. 一致性策略（最终一致性）

### 9.1 已确认取舍
- A 文章 HTML 缓存包含 B 的标题/缩略图
- B 更新时，不主动刷新所有引用 A 的缓存
- 通过 TTL/下次 A 重算更新，保持最终一致性

### 9.2 可选增强（默认不实现）
- 反向索引表维护引用关系（复杂度高），未来若业务需要强一致再做。

---

## 10. 安全与兼容

- 所有输出做转义：
  - title：`esc_html`
  - url：`esc_url`
  - tag name：`esc_html`
- Shortcode 参数 `absint`
- 多站点：表名使用 `$wpdb->prefix`
- 排除 revision/autosave
- 支持主题模板覆盖
- 不依赖外部服务（仅 WP + MySQL + Redis）

---

## 11. 性能验收标准（必须）

### 11.1 缓存命中（HTML）
- 目标：`rr_related_posts()` 仅 `wp_cache_get` + echo，插件自身基本 0 SQL

### 11.2 缓存未命中但 DB 有记录
- 目标：1 次 SQL（rr_related）+ 1 次 WP_Query（30 posts）+ 渲染 + 写缓存
- 下一次请求应命中 HTML

### 11.3 防击穿（关键演练）
- Redis 重启或大量 key 失效时：
  - 不出现大量重复 enqueue（queued 去重生效）
  - 热点文章不会出现多请求同时重建（single-flight lock 生效）
  - DB QPS 峰值可控（negative caching 生效）

---

## 12. 测试清单（CODEX 完成后必须自测）

1. 激活插件：建表成功  
2. Shortcode 输出正常（不足 N 时输出实际条数）  
3. 修改文章 tags/cats：只入队一次（去重生效），异步完成后相关文章更新  
4. Redis 暂停：仍能从 DB 读并输出（功能正常）  
5. 缓存击穿模拟（删除某热点文章 html/ids）：并发下不产生任务风暴（锁/负缓存/去重生效）  
6. WP-CLI warmup：跑完整体不 OOM（batch + 清理生效）  
7. 模板覆盖生效：主题目录 `rr/related-list.php` 被加载  

---
