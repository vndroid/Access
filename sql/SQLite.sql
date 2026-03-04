CREATE TABLE `typecho_access` (
  `id`                INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  `ua`                varchar(255)     default ''  ,
  `browser_id`        varchar(32)      default ''  ,
  `browser_version`   varchar(32)      default ''  ,
  `os_id`             varchar(32)      default ''  ,
  `os_version`        varchar(32)      default ''  ,
  `url`               varchar(255)     default ''  ,
  `path`              varchar(255)     default ''  ,
  `query_string`      varchar(255)     default ''  ,
  `ip`                char(38)         default '0' ,
  `entrypoint`        varchar(255)     default ''  ,
  `entrypoint_domain` varchar(100)     default ''  ,
  `referer`           varchar(255)     default ''  ,
  `referer_domain`    varchar(100)     default ''  ,
  `time`              int(32)          default '0' ,
  `content_id`        int(10)          default NULL,
  `meta_id`           int(10)          default NULL,
  `robot`             tinyint(1)       default '0' ,
  `robot_id`          varchar(32)      default ''  ,
  `robot_version`     varchar(32)      default ''
);
CREATE INDEX `typecho_access_time`              ON `typecho_access` (`time`             );
CREATE INDEX `typecho_access_path`              ON `typecho_access` (`path`             );
CREATE INDEX `typecho_access_ip_ua`             ON `typecho_access` (`ip`, `ua`         );
CREATE INDEX `typecho_access_robot`             ON `typecho_access` (`robot`, `time`    );
CREATE INDEX `typecho_access_os_id`             ON `typecho_access` (`os_id`            );
CREATE INDEX `typecho_access_robot_id`          ON `typecho_access` (`robot_id`         );
CREATE INDEX `typecho_access_browser_id`        ON `typecho_access` (`browser_id`       );
CREATE INDEX `typecho_access_content_id`        ON `typecho_access` (`content_id`       );
CREATE INDEX `typecho_access_meta_id`           ON `typecho_access` (`meta_id`          );
CREATE INDEX `typecho_access_entrypoint`        ON `typecho_access` (`entrypoint`       );
CREATE INDEX `typecho_access_entrypoint_domain` ON `typecho_access` (`entrypoint_domain`);
CREATE INDEX `typecho_access_referer`           ON `typecho_access` (`referer`          );
CREATE INDEX `typecho_access_referer_domain`    ON `typecho_access` (`referer_domain`   );
COMMIT;
