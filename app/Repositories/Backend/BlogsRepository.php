<?php

namespace App\Repositories\Backend;

use DB;
use Carbon\Carbon;
use App\Models\Blog;
use App\Models\BlogTag;
use App\Models\BlogMapTag;
use Illuminate\Support\Str;
use App\Models\BlogCategory;
use App\Models\BlogMapCategory;
use App\Exceptions\GeneralException;
use App\Repositories\BaseRepository;
use Illuminate\Support\Facades\Storage;
use App\Events\Backend\Blogs\BlogCreated;
use App\Events\Backend\Blogs\BlogDeleted;
use App\Events\Backend\Blogs\BlogUpdated;

class BlogsRepository extends BaseRepository
{
    /**
     * Associated Repository Model.
     */
    const MODEL = Blog::class;

    protected $upload_path;

    /**
     * Storage Class Object.
     *
     * @var \Illuminate\Support\Facades\Storage
     */
    protected $storage;

    public function __construct()
    {
        $this->upload_path = 'img'.DIRECTORY_SEPARATOR.'blog'.DIRECTORY_SEPARATOR;
        $this->storage = Storage::disk('public');
    }

    /**
     * @param int    $paged
     * @param string $orderBy
     * @param string $sort
     *
     * @return mixed
     */
    public function getActivePaginated($paged = 25, $orderBy = 'created_at', $sort = 'desc')
    {
        return $this->query()
            ->leftjoin('users', 'users.id', '=', 'blogs.created_by')
            ->select([
                'blogs.id',
                'blogs.name',
                'blogs.publish_datetime',
                'blogs.status',
                'blogs.created_by',
                'blogs.created_at',
                'users.first_name as user_name',
            ])
            ->orderBy($orderBy, $sort)
            ->paginate($paged);
    }

    /**
     * @return mixed
     */
    public function getForDataTable()
    {
        return $this->query()
            ->leftjoin('users', 'users.id', '=', 'blogs.created_by')
            ->select([
                'blogs.id',
                'blogs.name',
                'blogs.publish_datetime',
                'blogs.status',
                'blogs.created_by',
                'blogs.created_at',
                'users.first_name as user_name',
            ]);
    }

    /**
     * @param array $input
     *
     * @throws \App\Exceptions\GeneralException
     *
     * @return bool
     */
    public function create(array $input)
    {
        $tagsArray = $this->createTags($input['tags']);
        $categoriesArray = $this->createCategories($input['categories']);

        unset($input['tags'], $input['categories']);

        return DB::transaction(function () use ($input, $tagsArray, $categoriesArray) {
            $input['slug'] = Str::slug($input['name']);
            $input['publish_datetime'] = Carbon::parse($input['publish_datetime']);
            $input['created_by'] = auth()->user()->id;

            $input = $this->uploadImage($input);

            if ($blog = Blog::create($input)) {
                // Inserting associated category's id in mapper table
                if (count($categoriesArray)) {
                    $blog->categories()->sync($categoriesArray);
                }

                // Inserting associated tag's id in mapper table
                if (count($tagsArray)) {
                    $blog->tags()->sync($tagsArray);
                }

                event(new BlogCreated($blog));

                return $blog;
            }

            throw new GeneralException(__('exceptions.backend.blogs.create_error'));
        });
    }

    /**
     * @param \App\Models\Blog $blog
     * @param array $input
     */
    public function update(Blog $blog, array $input)
    {
        $tagsArray = $this->createTags($input['tags']);
        $categoriesArray = $this->createCategories($input['categories']);

        unset($input['tags'], $input['categories']);

        $input['slug'] = Str::slug($input['name']);
        $input['updated_by'] = auth()->user()->id;
        $input['publish_datetime'] = Carbon::parse($input['publish_datetime']);

        // Uploading Image
        if (array_key_exists('featured_image', $input)) {
            $this->deleteOldFile($blog);
            $input = $this->uploadImage($input);
        }

        return DB::transaction(function () use ($blog, $input, $tagsArray, $categoriesArray) {
            if ($blog->update($input)) {
                // Updateing associated category's id in mapper table
                if (count($categoriesArray)) {
                    $blog->categories()->sync($categoriesArray);
                }

                // Updating associated tag's id in mapper table
                if (count($tagsArray)) {
                    $blog->tags()->sync($tagsArray);
                }

                event(new BlogUpdated($blog));

                return $blog;
            }

            throw new GeneralException(__('exceptions.backend.blogs.update_error'));
        });
    }

    /**
     * Creating Tags.
     *
     * @param array $tags
     *
     * @return array
     */
    public function createTags($tags)
    {
        //Creating a new array for tags (newly created)
        $tags_array = [];

        foreach ($tags as $tag) {
            if (is_numeric($tag)) {
                $tags_array[] = $tag;
            } else {
                $newTag = BlogTag::create(['name' => $tag, 'status' => 1, 'created_by' => 1]);
                $tags_array[] = $newTag->id;
            }
        }

        return $tags_array;
    }

    /**
     * Creating Categories.
     *
     * @param array $categories
     *
     * @return array
     */
    public function createCategories($categories)
    {
        //Creating a new array for categories (newly created)
        $categories_array = [];

        foreach ($categories as $category) {
            if (is_numeric($category)) {
                $categories_array[] = $category;
            } else {
                $newCategory = BlogCategory::create(['name' => $category, 'status' => 1, 'created_by' => 1]);

                $categories_array[] = $newCategory->id;
            }
        }

        return $categories_array;
    }

    /**
     * @param \App\Models\Blogs\Blog $blog
     *
     * @throws GeneralException
     *
     * @return bool
     */
    public function delete(Blog $blog)
    {
        DB::transaction(function () use ($blog) {
            if ($blog->delete()) {
                BlogMapCategory::where('blog_id', $blog->id)->delete();
                BlogMapTag::where('blog_id', $blog->id)->delete();

                event(new BlogDeleted($blog));

                return true;
            }

            throw new GeneralException(__('exceptions.backend.blogs.delete_error'));
        });
    }

    /**
     * Upload Image.
     *
     * @param array $input
     *
     * @return array $input
     */
    public function uploadImage($input)
    {
        $avatar = $input['featured_image'];

        if (isset($input['featured_image']) && ! empty($input['featured_image'])) {
            $fileName = time().$avatar->getClientOriginalName();

            $this->storage->put($this->upload_path.$fileName, file_get_contents($avatar->getRealPath()));

            $input = array_merge($input, ['featured_image' => $fileName]);

            return $input;
        }
    }

    /**
     * Destroy Old Image.
     *
     * @param int $id
     */
    public function deleteOldFile($model)
    {
        $fileName = $model->featured_image;

        return $this->storage->delete($this->upload_path.$fileName);
    }
}
