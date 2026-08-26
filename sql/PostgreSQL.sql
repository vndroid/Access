CREATE TABLE typecho_access (
  "id"                SERIAL       NOT NULL,
  "ua"                varchar(255) DEFAULT '',
  "browser_id"        varchar(32)  DEFAULT '',
  "browser_version"   varchar(32)  DEFAULT '',
  "os_id"             varchar(32)  DEFAULT '',
  "os_version"        varchar(32)  DEFAULT '',
  "url"               varchar(255) DEFAULT '',
  "path"              varchar(255) DEFAULT '',
  "query_string"      varchar(255) DEFAULT '',
  "ip"                varchar(39)  DEFAULT '0',
  "entrypoint"        varchar(255) DEFAULT '',
  "entrypoint_domain" varchar(100) DEFAULT '',
  "referer"           varchar(255) DEFAULT '',
  "referer_domain"    varchar(100) DEFAULT '',
  "time"              bigint       DEFAULT 0,
  "content_id"        integer      DEFAULT NULL,
  "meta_id"           integer      DEFAULT NULL,
  "robot"             smallint     DEFAULT 0,
  "robot_id"          varchar(32)  DEFAULT '',
  "robot_version"     varchar(32)  DEFAULT '',
  "event_id"          varchar(32)  DEFAULT NULL,
  PRIMARY KEY ("id")
);
CREATE UNIQUE INDEX typecho_access_event_id     ON typecho_access ("event_id"         );
CREATE INDEX typecho_access_time              ON typecho_access ("time"             );
CREATE INDEX typecho_access_time_ip           ON typecho_access ("time", "ip"       );
CREATE INDEX typecho_access_time_ip_ua        ON typecho_access ("time", "ip", "ua" );
CREATE INDEX typecho_access_path              ON typecho_access ("path"             );
CREATE INDEX typecho_access_ip_ua             ON typecho_access ("ip", "ua"         );
CREATE INDEX typecho_access_robot             ON typecho_access ("robot", "time"    );
CREATE INDEX typecho_access_os_id             ON typecho_access ("os_id"            );
CREATE INDEX typecho_access_robot_id          ON typecho_access ("robot_id"         );
CREATE INDEX typecho_access_browser_id        ON typecho_access ("browser_id"       );
CREATE INDEX typecho_access_content_id        ON typecho_access ("content_id"       );
CREATE INDEX typecho_access_meta_id           ON typecho_access ("meta_id"          );
CREATE INDEX typecho_access_entrypoint        ON typecho_access ("entrypoint"       );
CREATE INDEX typecho_access_entrypoint_domain ON typecho_access ("entrypoint_domain");
CREATE INDEX typecho_access_referer           ON typecho_access ("referer"          );
CREATE INDEX typecho_access_referer_domain    ON typecho_access ("referer_domain"   );
COMMENT ON COLUMN typecho_access."id"                IS '序号';
COMMENT ON COLUMN typecho_access."ua"                IS 'UA';
COMMENT ON COLUMN typecho_access."browser_id"        IS '浏览器名称';
COMMENT ON COLUMN typecho_access."browser_version"   IS '浏览器版本';
COMMENT ON COLUMN typecho_access."os_id"             IS '系统名称';
COMMENT ON COLUMN typecho_access."os_version"        IS '系统版本';
COMMENT ON COLUMN typecho_access."url"               IS '统一资源定位符';
COMMENT ON COLUMN typecho_access."path"              IS '路径';
COMMENT ON COLUMN typecho_access."query_string"      IS '请求参数';
COMMENT ON COLUMN typecho_access."ip"                IS 'IP';
COMMENT ON COLUMN typecho_access."entrypoint"        IS '入口点';
COMMENT ON COLUMN typecho_access."entrypoint_domain" IS '入口域名';
COMMENT ON COLUMN typecho_access."referer"           IS 'Referer';
COMMENT ON COLUMN typecho_access."referer_domain"    IS '来源域名';
COMMENT ON COLUMN typecho_access."time"              IS '时间戳';
COMMENT ON COLUMN typecho_access."content_id"        IS '内容';
COMMENT ON COLUMN typecho_access."meta_id"           IS '索引序号';
COMMENT ON COLUMN typecho_access."robot"             IS '是否爬虫';
COMMENT ON COLUMN typecho_access."robot_id"          IS '爬虫名称';
COMMENT ON COLUMN typecho_access."robot_version"     IS '爬虫版本';
COMMENT ON COLUMN typecho_access."event_id"          IS '事件唯一标识';
