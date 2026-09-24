SET NAMES utf8mb4;

SET @ru := (SELECT id FROM users WHERE username = 'docsru' LIMIT 1);
SET @en := (SELECT id FROM users WHERE username = 'docsen' LIMIT 1);

UPDATE statuses SET name='Входящие', description='Новые задачи', color='#64748b' WHERE user_id=@ru AND project_id IS NULL AND is_default=1;
UPDATE statuses SET name='В работе', description='Задачи в процессе', color='#3b82f6' WHERE user_id=@ru AND project_id IS NULL AND is_default=0 AND is_completion=0;
UPDATE statuses SET name='Готово', description='Завершённые задачи', color='#22c55e' WHERE user_id=@ru AND project_id IS NULL AND is_completion=1;
UPDATE task_types SET name='Обычная', description='Обычная рабочая задача' WHERE user_id=@ru;

INSERT INTO users (username,email,password_hash,role,is_active,email_verified_at,approved_at)
VALUES
('anna-demo','anna@example.invalid','not-used','user',1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('sam-demo','sam@example.invalid','not-used','user',1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('vera-demo','vera@example.invalid','not-used','user',1,UTC_TIMESTAMP(),UTC_TIMESTAMP()),
('jordan-demo','jordan@example.invalid','not-used','user',1,UTC_TIMESTAMP(),UTC_TIMESTAMP());

SET @anna := (SELECT id FROM users WHERE username='anna-demo' LIMIT 1);
SET @sam := (SELECT id FROM users WHERE username='sam-demo' LIMIT 1);
SET @vera := (SELECT id FROM users WHERE username='vera-demo' LIMIT 1);
SET @jordan := (SELECT id FROM users WHERE username='jordan-demo' LIMIT 1);

INSERT INTO user_preferences (user_id,timezone,theme) VALUES
(@ru,'Europe/Moscow','paper'),
(@en,'Europe/Moscow','paper')
ON DUPLICATE KEY UPDATE timezone=VALUES(timezone),theme=VALUES(theme);
INSERT INTO teams (name,description,created_by) VALUES
('Команда документации','Подготовка релиза, руководств и учебных материалов',@ru),
('Documentation team','Release notes, manuals and learning materials',@en);
SET @team_ru := (SELECT id FROM teams WHERE created_by=@ru AND name='Команда документации' LIMIT 1);
SET @team_en := (SELECT id FROM teams WHERE created_by=@en AND name='Documentation team' LIMIT 1);

INSERT INTO team_members (team_id,user_id,role) VALUES
(@team_ru,@ru,'lead'),(@team_ru,@anna,'member'),
(@team_en,@en,'lead'),(@team_en,@sam,'member');

INSERT INTO team_invitations (team_id,invited_user_id,invited_by,status,expires_at)
VALUES
(@team_ru,@vera,@ru,'pending','2026-10-01 12:00:00'),
(@team_en,@jordan,@en,'pending','2026-10-01 12:00:00');

INSERT INTO projects (owner_user_id,owner_team_id,created_by,name,description,lifecycle_status) VALUES
(NULL,@team_ru,@ru,'Релиз 0.5','Подготовка следующего выпуска TMS: интерфейс, документация и проверка сценариев.','active'),
(NULL,@team_en,@en,'Release 0.5','Prepare the next TMS release: interface, documentation and workflow verification.','active');
SET @project_ru := (SELECT id FROM projects WHERE created_by=@ru AND name='Релиз 0.5' LIMIT 1);
SET @project_en := (SELECT id FROM projects WHERE created_by=@en AND name='Release 0.5' LIMIT 1);

INSERT INTO statuses (user_id,project_id,source_status_id,name,description,color,sort_order,is_default,is_completion,show_on_board) VALUES
(NULL,@project_ru,NULL,'Идеи','Ещё не взято в работу','#64748b',10,1,0,1),
(NULL,@project_ru,NULL,'В работе','Активная работа','#3b82f6',20,0,0,1),
(NULL,@project_ru,NULL,'Готово','Завершено','#22c55e',30,0,1,1),
(NULL,@project_en,NULL,'Ideas','Not started yet','#64748b',10,1,0,1),
(NULL,@project_en,NULL,'In progress','Active work','#3b82f6',20,0,0,1),
(NULL,@project_en,NULL,'Done','Completed','#22c55e',30,0,1,1);

SET @ru_ideas := (SELECT id FROM statuses WHERE project_id=@project_ru AND is_default=1 LIMIT 1);
SET @ru_doing := (SELECT id FROM statuses WHERE project_id=@project_ru AND name='В работе' LIMIT 1);
SET @ru_done := (SELECT id FROM statuses WHERE project_id=@project_ru AND is_completion=1 LIMIT 1);
SET @en_ideas := (SELECT id FROM statuses WHERE project_id=@project_en AND is_default=1 LIMIT 1);
SET @en_doing := (SELECT id FROM statuses WHERE project_id=@project_en AND name='In progress' LIMIT 1);
SET @en_done := (SELECT id FROM statuses WHERE project_id=@project_en AND is_completion=1 LIMIT 1);
SET @ru_type := (SELECT id FROM task_types WHERE user_id=@ru ORDER BY id LIMIT 1);
SET @en_type := (SELECT id FROM task_types WHERE user_id=@en ORDER BY id LIMIT 1);

INSERT INTO tasks (created_by,title,description,deadline,scheduled_at,status_id,type_id,priority,project_id,assignee_user_id,created_at) VALUES
(@ru,'Собрать замечания к интерфейсу','<p>Свести обратную связь после развёртывания и выделить повторяющиеся замечания.</p>','2026-09-26 18:00:00','2026-09-24 10:00:00',@ru_ideas,@ru_type,1,@project_ru,@anna,'2026-09-20 09:10:00'),
(@ru,'Обновить руководство пользователя','<p>Проверить сценарии, подписи и примеры перед публикацией обновлённого учебника.</p>','2026-09-25 18:00:00','2026-09-24 14:00:00',@ru_doing,@ru_type,2,@project_ru,@ru,'2026-09-20 10:20:00'),
(@ru,'Проверить календарь и сроки','<p>Убедиться, что плановое время и крайний срок отображаются независимо.</p>','2026-09-28 17:00:00','2026-09-25 11:00:00',@ru_doing,@ru_type,1,@project_ru,@anna,'2026-09-21 08:40:00'),
(@ru,'Подготовить заметки к релизу','<p>Собрать пользовательские изменения и короткие примеры использования.</p>','2026-09-29 16:00:00',NULL,@ru_ideas,@ru_type,1,@project_ru,@ru,'2026-09-21 12:15:00'),
(@ru,'Прогнать smoke-тесты','<p>Проверить основные пользовательские сценарии перед публикацией.</p>','2026-09-23 15:00:00','2026-09-23 10:00:00',@ru_done,@ru_type,3,@project_ru,@ru,'2026-09-22 09:00:00'),
(@ru,'Еженедельный обзор проекта','<p>Коротко пройтись по задачам команды и обновить план на неделю.</p>','2026-09-28 11:00:00','2026-09-28 10:00:00',@ru_doing,@ru_type,1,@project_ru,@ru,'2026-09-22 11:30:00'),
(@ru,'Сверить переводы интерфейса','<p>Проверить новые строки интерфейса в русской локализации.</p>','2026-09-30 13:00:00','2026-09-29 15:00:00',@ru_ideas,@ru_type,0,@project_ru,@anna,'2026-09-23 08:30:00');

INSERT INTO tasks (created_by,title,description,deadline,scheduled_at,status_id,type_id,priority,project_id,assignee_user_id,created_at) VALUES
(@en,'Collect interface feedback','<p>Consolidate feedback after deployment and identify recurring UI issues.</p>','2026-09-26 18:00:00','2026-09-24 10:00:00',@en_ideas,@en_type,1,@project_en,@sam,'2026-09-20 09:10:00'),
(@en,'Update the user handbook','<p>Review workflows, captions and examples before publishing the revised handbook.</p>','2026-09-25 18:00:00','2026-09-24 14:00:00',@en_doing,@en_type,2,@project_en,@en,'2026-09-20 10:20:00'),
(@en,'Verify calendar and deadlines','<p>Confirm that planned time and hard deadline are presented independently.</p>','2026-09-28 17:00:00','2026-09-25 11:00:00',@en_doing,@en_type,1,@project_en,@sam,'2026-09-21 08:40:00'),
(@en,'Prepare release notes','<p>Summarize user-facing changes and add short usage examples.</p>','2026-09-29 16:00:00',NULL,@en_ideas,@en_type,1,@project_en,@en,'2026-09-21 12:15:00'),
(@en,'Run smoke tests','<p>Verify the main user workflows before publication.</p>','2026-09-23 15:00:00','2026-09-23 10:00:00',@en_done,@en_type,3,@project_en,@en,'2026-09-22 09:00:00'),
(@en,'Weekly project review','<p>Review team tasks and refresh the plan for the coming week.</p>','2026-09-28 11:00:00','2026-09-28 10:00:00',@en_doing,@en_type,1,@project_en,@en,'2026-09-22 11:30:00'),
(@en,'Review interface translations','<p>Check new user-facing strings in the English interface.</p>','2026-09-30 13:00:00','2026-09-29 15:00:00',@en_ideas,@en_type,0,@project_en,@sam,'2026-09-23 08:30:00');

SET @ru_handbook := (SELECT id FROM tasks WHERE created_by=@ru AND title='Обновить руководство пользователя' LIMIT 1);
SET @en_handbook := (SELECT id FROM tasks WHERE created_by=@en AND title='Update the user handbook' LIMIT 1);
SET @ru_weekly := (SELECT id FROM tasks WHERE created_by=@ru AND title='Еженедельный обзор проекта' LIMIT 1);
SET @en_weekly := (SELECT id FROM tasks WHERE created_by=@en AND title='Weekly project review' LIMIT 1);
INSERT INTO task_checklist_items (task_id,item_text,is_completed,sort_order) VALUES
(@ru_handbook,'Проверить быстрый старт',1,10),
(@ru_handbook,'Добавить примеры рабочих сценариев',1,20),
(@ru_handbook,'Проверить подписи к иллюстрациям',0,30),
(@ru_handbook,'Собрать финальный PDF',0,40),
(@en_handbook,'Review the quick start',1,10),
(@en_handbook,'Add practical workflow examples',1,20),
(@en_handbook,'Review illustration captions',0,30),
(@en_handbook,'Build the final PDF',0,40);

INSERT INTO task_recurrences (owner_user_id,current_task_id,mode,interval_value,spawn_status_id,timezone,next_deadline,next_run_at,sequence,is_active) VALUES
(@ru,@ru_weekly,'weekly',1,@ru_ideas,'Europe/Moscow','2026-10-05 11:00:00','2026-09-28 11:00:00',0,1),
(@en,@en_weekly,'weekly',1,@en_ideas,'Europe/Moscow','2026-10-05 11:00:00','2026-09-28 11:00:00',0,1);

INSERT INTO task_saved_views (user_id,name,query_json,is_default) VALUES
(@ru,'Срочные и важные','{"project":"all","priority":["high","urgent"],"sort":"deadline","order":"asc","per_page":25}',0),
(@ru,'Моя работа на неделю','{"project":"all","sort":"scheduled_at","order":"asc","per_page":25}',1),
(@en,'High priority','{"project":"all","priority":["high","urgent"],"sort":"deadline","order":"asc","per_page":25}',0),
(@en,'My week','{"project":"all","sort":"scheduled_at","order":"asc","per_page":25}',1);

INSERT INTO discussion_comments (project_id,task_id,team_id,parent_comment_id,author_user_id,body_html,created_at,updated_at) VALUES
(@project_ru,NULL,@team_ru,NULL,@anna,'<p>@docsru Я проверила календарь: плановое время и срок теперь хорошо различаются. Осталось добавить скриншоты в учебник.</p>','2026-09-23 12:10:00','2026-09-23 12:10:00'),
(NULL,@ru_handbook,@team_ru,NULL,@ru,'<p>Для этого раздела нужен пример с чек-листом и отдельный снимок быстрого редактирования.</p>','2026-09-23 13:30:00','2026-09-23 13:30:00'),
(@project_en,NULL,@team_en,NULL,@sam,'<p>@docsen I checked the calendar: planned time and deadline are now clearly separated. We only need handbook screenshots.</p>','2026-09-23 12:10:00','2026-09-23 12:10:00'),
(NULL,@en_handbook,@team_en,NULL,@en,'<p>This section needs a checklist example and a separate quick-edit screenshot.</p>','2026-09-23 13:30:00','2026-09-23 13:30:00');

SET @ru_project_comment := (SELECT id FROM discussion_comments WHERE project_id=@project_ru ORDER BY id LIMIT 1);
SET @en_project_comment := (SELECT id FROM discussion_comments WHERE project_id=@project_en ORDER BY id LIMIT 1);
INSERT INTO internal_notifications
(user_id,actor_user_id,actor_username,notification_type,context_label,body_preview,target_url,project_id,task_id,comment_id,dedupe_key,read_at,created_at) VALUES
(@ru,@anna,'anna-demo','discussion_mention','Релиз 0.5','Я проверила календарь: плановое время и срок теперь хорошо различаются.',CONCAT('/projects/',@project_ru,'/discussion'),@project_ru,NULL,@ru_project_comment,SHA2(CONCAT('ru-mention-',@ru),256),NULL,'2026-09-23 12:10:00'),
(@ru,@anna,'anna-demo','task_status_changed','Обновить руководство пользователя','Статус изменён на «В работе».',CONCAT('/tasks/',@ru_handbook,'/edit'),@project_ru,@ru_handbook,NULL,SHA2(CONCAT('ru-status-',@ru),256),NULL,'2026-09-23 14:00:00'),
(@ru,NULL,'TMS','reminder_upcoming','Подготовить заметки к релизу','До срока осталось меньше двух дней.',CONCAT('/tasks/',(SELECT id FROM tasks WHERE created_by=@ru AND title='Подготовить заметки к релизу' LIMIT 1),'/edit'),@project_ru,(SELECT id FROM tasks WHERE created_by=@ru AND title='Подготовить заметки к релизу' LIMIT 1),NULL,SHA2(CONCAT('ru-reminder-',@ru),256),'2026-09-23 15:00:00','2026-09-23 11:00:00'),
(@en,@sam,'sam-demo','discussion_mention','Release 0.5','I checked the calendar: planned time and deadline are now clearly separated.',CONCAT('/projects/',@project_en,'/discussion'),@project_en,NULL,@en_project_comment,SHA2(CONCAT('en-mention-',@en),256),NULL,'2026-09-23 12:10:00'),
(@en,@sam,'sam-demo','task_status_changed','Update the user handbook','Status changed to In progress.',CONCAT('/tasks/',@en_handbook,'/edit'),@project_en,@en_handbook,NULL,SHA2(CONCAT('en-status-',@en),256),NULL,'2026-09-23 14:00:00'),
(@en,NULL,'TMS','reminder_upcoming','Prepare release notes','The deadline is less than two days away.',CONCAT('/tasks/',(SELECT id FROM tasks WHERE created_by=@en AND title='Prepare release notes' LIMIT 1),'/edit'),@project_en,(SELECT id FROM tasks WHERE created_by=@en AND title='Prepare release notes' LIMIT 1),NULL,SHA2(CONCAT('en-reminder-',@en),256),'2026-09-23 15:00:00','2026-09-23 11:00:00');

INSERT INTO notification_settings
(user_id,email_enabled,email_address,telegram_enabled,telegram_chat_id,telegram_username,notify_task_assignments,notify_task_dates,notify_task_status,notify_tomorrow,tomorrow_time,notify_upcoming,urgent_minutes,high_minutes,medium_minutes,low_minutes,notify_overdue,overdue_time,notify_digest,digest_time)
VALUES
(@ru,1,'docsru@example.invalid',0,NULL,NULL,1,1,1,1,'08:00:00',1,15,60,240,1440,1,'09:00:00',1,'18:30:00'),
(@en,1,'docsen@example.invalid',0,NULL,NULL,1,1,1,1,'08:00:00',1,15,60,240,1440,1,'09:00:00',1,'18:30:00')
ON DUPLICATE KEY UPDATE
email_enabled=VALUES(email_enabled),email_address=VALUES(email_address),notify_task_assignments=VALUES(notify_task_assignments),
notify_task_dates=VALUES(notify_task_dates),notify_task_status=VALUES(notify_task_status),notify_tomorrow=VALUES(notify_tomorrow),
notify_upcoming=VALUES(notify_upcoming),notify_overdue=VALUES(notify_overdue),notify_digest=VALUES(notify_digest),digest_time=VALUES(digest_time);

SELECT @ru AS ru_user,@en AS en_user,@team_ru AS ru_team,@team_en AS en_team,@project_ru AS ru_project,@project_en AS en_project,@ru_handbook AS ru_task,@en_handbook AS en_task;