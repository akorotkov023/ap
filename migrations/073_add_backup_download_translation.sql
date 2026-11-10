-- Add translation for backups.download
INSERT INTO translations (`locale`, `category`, `key_name`, `translation`) VALUES
('en', 'backups', 'download', 'Download'),
('ru', 'backups', 'download', 'Скачать'),
('es', 'backups', 'download', 'Descargar'),
('de', 'backups', 'download', 'Herunterladen'),
('fr', 'backups', 'download', 'Télécharger'),
('zh', 'backups', 'download', '下载')
ON DUPLICATE KEY UPDATE `translation` = VALUES(`translation`);
