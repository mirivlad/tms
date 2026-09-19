<?php

declare(strict_types=1);

namespace Tms\Tests\Domain\Discussion;

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tms\Domain\Discussion\DiscussionRepository;

final class DiscussionRepositoryTest extends TestCase
{
    private PDO $db;
    private DiscussionRepository $discussions;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('PRAGMA foreign_keys = ON');

        $this->db->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL
        )');
        $this->db->exec('CREATE TABLE teams (
            id INTEGER PRIMARY KEY
        )');
        $this->db->exec('CREATE TABLE team_members (
            team_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            PRIMARY KEY (team_id, user_id)
        )');
        $this->db->exec('CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            owner_user_id INTEGER NULL,
            owner_team_id INTEGER NULL
        )');
        $this->db->exec('CREATE TABLE tasks (
            id INTEGER PRIMARY KEY,
            project_id INTEGER NULL
        )');
        $this->db->exec('CREATE TABLE discussion_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NULL,
            task_id INTEGER NULL,
            team_id INTEGER NULL,
            parent_comment_id INTEGER NULL,
            author_user_id INTEGER NULL,
            body_html TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT NULL,
            FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
            FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
            FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
            FOREIGN KEY (parent_comment_id) REFERENCES discussion_comments (id) ON DELETE CASCADE,
            FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE SET NULL
        )');

        $this->db->exec("INSERT INTO users (id, username) VALUES
            (1, 'lead'), (2, 'member'), (3, 'otherlead'), (4, 'outsider')");
        $this->db->exec('INSERT INTO teams (id) VALUES (7), (8)');
        $this->db->exec("INSERT INTO team_members (team_id,user_id,role) VALUES
            (7,1,'lead'),(7,2,'member'),(8,3,'lead')");
        $this->db->exec('INSERT INTO projects (id,owner_user_id,owner_team_id) VALUES
            (10,NULL,7),(20,4,NULL),(30,NULL,8)');
        $this->db->exec('INSERT INTO tasks (id,project_id) VALUES (100,10),(200,20)');

        $this->discussions = new DiscussionRepository($this->db);
    }

    public function testTeamMembersCanCreateProjectAndTaskThreads(): void
    {
        $root = $this->discussions->createForProject(2, 10, '<p>Project context</p>');
        $reply = $this->discussions->createForProject(1, 10, '<p>Lead reply</p>', $root);
        $taskComment = $this->discussions->createForTask(1, 100, '<p>Task context</p>');

        $project = $this->discussions->listForProject(2, 10);
        self::assertSame([$root, $reply], array_map(static fn ($comment): int => $comment->id, $project));
        self::assertNull($project[0]->parentCommentId);
        self::assertSame($root, $project[1]->parentCommentId);
        self::assertSame('member', $project[0]->authorUsername);
        self::assertSame('7', (string) $this->db->query(
            'SELECT team_id FROM discussion_comments WHERE id = ' . $root
        )->fetchColumn());
        self::assertSame([$taskComment], array_map(
            static fn ($comment): int => $comment->id,
            $this->discussions->listForTask(2, 100),
        ));
    }

    public function testPersonalProjectAndTaskDoNotExposeDiscussion(): void
    {
        self::assertSame([], $this->discussions->listForProject(4, 20));
        self::assertSame([], $this->discussions->listForTask(4, 200));

        $this->expectException(DomainException::class);
        $this->discussions->createForProject(4, 20, '<p>private discussion</p>');
    }

    public function testRepliesAreLimitedToOneLevelAndSameContext(): void
    {
        $root = $this->discussions->createForProject(1, 10, '<p>Root</p>');
        $reply = $this->discussions->createForProject(2, 10, '<p>Reply</p>', $root);

        try {
            $this->discussions->createForProject(1, 10, '<p>Too deep</p>', $reply);
            self::fail('Reply to reply should be rejected.');
        } catch (DomainException $error) {
            self::assertSame('Replies can only be one level deep.', $error->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Reply target is unavailable.');
        $this->discussions->createForTask(1, 100, '<p>Wrong context</p>', $root);
    }

    public function testAuthorEditsOwnCommentAndLeadCanModerateAnyComment(): void
    {
        $root = $this->discussions->createForProject(2, 10, '<p>Original</p>');
        self::assertFalse($this->discussions->updateForProject(1, 10, $root, '<p>Lead edit forbidden</p>'));
        self::assertTrue($this->discussions->updateForProject(2, 10, $root, '<p>Edited by author</p>'));
        self::assertStringContainsString('Edited by author', $this->discussions->listForProject(2, 10)[0]->bodyHtml);

        self::assertTrue($this->discussions->deleteForProject(1, 10, $root));
        $deleted = $this->discussions->listForProject(2, 10)[0];
        self::assertTrue($deleted->isDeleted());
        self::assertSame('', $deleted->bodyHtml);
    }

    public function testMemberCannotDeleteAnotherMembersCommentAndSoftDeleteKeepsReplies(): void
    {
        $root = $this->discussions->createForProject(1, 10, '<p>Lead root</p>');
        $reply = $this->discussions->createForProject(2, 10, '<p>Member reply</p>', $root);

        self::assertFalse($this->discussions->deleteForProject(2, 10, $root));
        self::assertTrue($this->discussions->deleteForProject(1, 10, $root));

        $comments = $this->discussions->listForProject(2, 10);
        self::assertCount(2, $comments);
        self::assertTrue($comments[0]->isDeleted());
        self::assertSame($reply, $comments[1]->id);
        self::assertFalse($comments[1]->isDeleted());
    }

    public function testTaskDiscussionKeepsOriginalTeamContextAfterMove(): void
    {
        $oldComment = $this->discussions->createForTask(2, 100, '<p>Before move</p>');
        self::assertCount(1, $this->discussions->listForTask(1, 100));
        self::assertSame('7', (string) $this->db->query(
            'SELECT team_id FROM discussion_comments WHERE id = ' . $oldComment
        )->fetchColumn());

        $this->db->exec('UPDATE tasks SET project_id = 30 WHERE id = 100');

        // The original Team 7 thread is no longer reachable through a task
        // that now belongs to Team 8, and it must not leak to Team 8 either.
        self::assertSame([], $this->discussions->listForTask(1, 100));
        self::assertSame([], $this->discussions->listForTask(2, 100));
        self::assertSame([], $this->discussions->listForTask(3, 100));

        $newComment = $this->discussions->createForTask(3, 100, '<p>After move</p>');
        self::assertSame('8', (string) $this->db->query(
            'SELECT team_id FROM discussion_comments WHERE id = ' . $newComment
        )->fetchColumn());
        self::assertSame([$newComment], array_map(
            static fn ($item): int => $item->id,
            $this->discussions->listForTask(3, 100),
        ));
    }

    public function testDeletingAuthorKeepsCommentAsHistoricalContext(): void
    {
        $commentId = $this->discussions->createForProject(2, 10, '<p>Keep me</p>');
        $this->db->exec('DELETE FROM users WHERE id = 2');

        $comment = $this->discussions->listForProject(1, 10)[0];
        self::assertSame($commentId, $comment->id);
        self::assertNull($comment->authorUserId);
        self::assertNull($comment->authorUsername);
    }

    public function testEmptySanitizedLikeBodyIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->discussions->createForProject(1, 10, '<p>&nbsp;</p>');
    }
}
