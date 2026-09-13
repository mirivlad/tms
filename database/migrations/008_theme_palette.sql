UPDATE user_preferences SET theme = 'graphite' WHERE theme = 'dark';
UPDATE user_preferences SET theme = 'paper' WHERE theme = 'light';
ALTER TABLE user_preferences MODIFY theme VARCHAR(16) NOT NULL DEFAULT 'graphite';
