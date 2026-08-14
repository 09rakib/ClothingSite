<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Blog\BlogRepository;

/**
 * Blog CMS (PROJECT_RULES.md §21).
 */
final class BlogRepositoryTest extends DatabaseTestCase
{
    private BlogRepository $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blog = new BlogRepository($this->db);
    }

    public function test_create_generates_a_slug(): void
    {
        $author = $this->createUser('admin@test.com', 'admin');

        $id = $this->blog->create('How to Care for Cotton', null, 'Body text here that is long enough.', null, 'draft', $author);

        $post = $this->blog->find($id);
        $this->assertSame('how-to-care-for-cotton', $post['slug']);
    }

    public function test_blank_excerpt_is_derived_from_the_body(): void
    {
        $author = $this->createUser('admin@test.com', 'admin');

        $id = $this->blog->create(
            'Title',
            null,
            'This is the full body of the post and it should be summarised automatically.',
            null,
            'draft',
            $author
        );

        $post = $this->blog->find($id);
        $this->assertNotSame('', trim((string) $post['excerpt']));
        $this->assertStringStartsWith('This is the full body', $post['excerpt']);
    }

    public function test_draft_posts_are_not_publicly_visible(): void
    {
        $author = $this->createUser('admin@test.com', 'admin');
        $this->blog->create('Draft Post', null, 'Not ready yet, still being written here.', null, 'draft', $author);

        $this->assertNull($this->blog->findPublishedBySlug('draft-post'));
        $this->assertSame(0, $this->blog->paginatePublished()['total']);
    }

    public function test_published_posts_are_publicly_visible(): void
    {
        $author = $this->createUser('admin@test.com', 'admin');
        $this->blog->create('Live Post', null, 'This one is ready for the world to see now.', null, 'published', $author);

        $found = $this->blog->findPublishedBySlug('live-post');
        $this->assertNotNull($found);
        $this->assertSame('Live Post', $found['title']);
    }

    public function test_publishing_stamps_a_published_at_date_once(): void
    {
        $author = $this->createUser('admin@test.com', 'admin');
        $id = $this->blog->create('Post', null, 'Body content that is definitely long enough here.', null, 'draft', $author);

        $this->assertNull($this->blog->find($id)['published_at']);

        $this->blog->update($id, 'Post', null, 'Body content that is definitely long enough here.', null, 'published');
        $firstPublishedAt = $this->blog->find($id)['published_at'];
        $this->assertNotNull($firstPublishedAt);

        // Editing again while still published must not bump the date.
        $this->blog->update($id, 'Post Updated', null, 'Body content that is definitely long enough here.', null, 'published');
        $this->assertSame($firstPublishedAt, $this->blog->find($id)['published_at']);
    }

    public function test_slug_is_stable_unless_the_title_changes(): void
    {
        $author = $this->createUser('admin@test.com', 'admin');
        $id = $this->blog->create('Stable Title', null, 'Original body text goes here for the post.', null, 'draft', $author);
        $slug = $this->blog->find($id)['slug'];

        $this->blog->update($id, 'Stable Title', null, 'Updated body text goes here instead now.', null, 'draft');

        $this->assertSame($slug, $this->blog->find($id)['slug']);
    }

    public function test_delete_removes_the_post(): void
    {
        $author = $this->createUser('admin@test.com', 'admin');
        $id = $this->blog->create('Gone Soon', null, 'This post will be deleted right after this.', null, 'draft', $author);

        $this->blog->delete($id);

        $this->assertNull($this->blog->find($id));
    }

    public function test_admin_listing_includes_drafts(): void
    {
        $author = $this->createUser('admin@test.com', 'admin');
        $this->blog->create('Draft', null, 'A draft post that is not published yet here.', null, 'draft', $author);
        $this->blog->create('Live', null, 'A published post that is visible to everyone.', null, 'published', $author);

        $this->assertCount(2, $this->blog->allForAdmin());
    }
}
