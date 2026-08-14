<?php

declare(strict_types=1);

/**
 * Admin — blog CMS (PROJECT_RULES.md §21 "Admin: create, edit, draft,
 * publish, unpublish").
 *
 * Featured images are intentionally out of scope here — the text CMS
 * (create/edit/publish/archive) is the part §21 actually requires; an image
 * uploader for blog posts can reuse ImageUploader later without changing
 * this form's shape.
 */

$pageTitle = 'Blog Posts';
require_once __DIR__ . '/../includes/admin-header.php';

use App\Audit\AuditLogger;
use App\Blog\BlogRepository;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Http;
use App\Support\Validator;
use App\Support\View;

$blog  = new BlogRepository();
$audit = new AuditLogger();

$errors  = [];
$old     = ['title' => '', 'excerpt' => '', 'body' => '', 'status' => 'draft'];
$editing = null;

if (Http::isPost()) {
    Csrf::verifyRequest();

    $action = (string) ($_POST['action'] ?? 'save');
    $postId = Http::intParam($_POST, 'post_id');

    if ($action === 'delete') {
        if ($postId === null || $blog->find($postId) === null) {
            Flash::error('That post no longer exists.');
        } else {
            $blog->delete($postId);
            $audit->log((int) Auth::id(), 'blog_post.deleted', 'blog_post', $postId);
            Flash::success('Post deleted.');
        }
        Http::redirect('blog.php');
    }

    $validator = (new Validator($_POST))
        ->label('title', 'Title')
        ->label('excerpt', 'Excerpt')
        ->label('body', 'Body')
        ->label('status', 'Status')
        ->required('title')->maxLength('title', 200)
        ->maxLength('excerpt', 300)
        ->required('body')->minLength('body', 20)
        ->required('status')->inList('status', ['draft', 'published']);

    foreach (array_keys($old) as $field) {
        $old[$field] = $validator->value($field);
    }

    $isUpdate = $postId !== null;
    if ($isUpdate && $blog->find($postId) === null) {
        Flash::error('That post no longer exists.');
        Http::redirect('blog.php');
    }

    if ($validator->passes()) {
        $excerpt = $old['excerpt'] !== '' ? $old['excerpt'] : null;

        if ($isUpdate) {
            $blog->update($postId, $old['title'], $excerpt, $old['body'], null, $old['status']);
            $audit->log((int) Auth::id(), 'blog_post.updated', 'blog_post', $postId, ['status' => $old['status']]);
            Flash::success('Post updated.');
        } else {
            $newId = $blog->create($old['title'], $excerpt, $old['body'], null, $old['status'], (int) Auth::id());
            $audit->log((int) Auth::id(), 'blog_post.created', 'blog_post', $newId, ['status' => $old['status']]);
            Flash::success('Post created.');
        }

        Http::redirect('blog.php');
    }

    $errors = $validator->errors();
    if ($isUpdate) {
        $editing = ['id' => $postId] + $old;
    }
}

$editId = Http::intParam($_GET, 'edit');
if ($editing === null && $editId !== null) {
    $found = $blog->find($editId);
    if ($found !== null) {
        $editing = [
            'id'      => (int) $found['id'],
            'title'   => (string) $found['title'],
            'excerpt' => (string) ($found['excerpt'] ?? ''),
            'body'    => (string) $found['body'],
            'status'  => (string) $found['status'],
        ];
    }
}

$posts = $blog->allForAdmin();
?>

<h1 class="page-heading">Blog Posts</h1>
<p class="page-subheading">Manage the store's blog content</p>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $message): ?><div><?= View::e($message) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="admin-split">
    <div class="admin-card">
        <h2 class="card-title"><?= $editing ? 'Edit Post' : 'New Post' ?></h2>

        <form method="post" action="blog.php" novalidate>
            <?= Csrf::field() ?>
            <?php if ($editing): ?><input type="hidden" name="post_id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" maxlength="200" required
                       value="<?= View::e($editing['title'] ?? $old['title']) ?>">
            </div>
            <div class="form-group">
                <label for="excerpt">Excerpt <span class="optional">(optional — auto-generated if left blank)</span></label>
                <textarea id="excerpt" name="excerpt" maxlength="300"><?= View::e($editing['excerpt'] ?? $old['excerpt']) ?></textarea>
            </div>
            <div class="form-group">
                <label for="body">Body</label>
                <textarea id="body" name="body" rows="10" required><?= View::e($editing['body'] ?? $old['body']) ?></textarea>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select name="status" id="status">
                    <option value="draft" <?= ($editing['status'] ?? $old['status']) === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="published" <?= ($editing['status'] ?? $old['status']) === 'published' ? 'selected' : '' ?>>Published</option>
                </select>
            </div>

            <button type="submit" class="btn btn-block"><?= $editing ? 'Save Changes' : 'Create Post' ?></button>
            <?php if ($editing): ?>
                <a href="blog.php" class="btn btn-block btn-outline mt-16" style="background:var(--color-primary);">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="admin-card admin-card-wide">
        <h2 class="card-title">All Posts (<?= count($posts) ?>)</h2>

        <?php if ($posts === []): ?>
            <div class="empty-state">No posts yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Title</th>
                            <th scope="col">Status</th>
                            <th scope="col">Author</th>
                            <th scope="col">Date</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posts as $post): ?>
                            <tr>
                                <td><?= View::e($post['title']) ?></td>
                                <td>
                                    <span class="status-pill <?= $post['status'] === 'published' ? 'status-active' : 'status-pending' ?>">
                                        <?= ucfirst($post['status']) ?>
                                    </span>
                                </td>
                                <td><?= View::e($post['author_name'] ?? 'Unknown') ?></td>
                                <td><?= View::e(date('d M Y', strtotime((string) $post['created_at']))) ?></td>
                                <td class="actions-cell">
                                    <a href="blog.php?edit=<?= (int) $post['id'] ?>" class="btn btn-sm btn-success">Edit</a>
                                    <?php if ($post['status'] === 'published'): ?>
                                        <a href="../blogpost.php?slug=<?= urlencode((string) $post['slug']) ?>" class="btn btn-sm">View</a>
                                    <?php endif; ?>
                                    <form method="post" action="blog.php" class="inline-form"
                                          onsubmit="return confirm('Delete this post permanently?');">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>
