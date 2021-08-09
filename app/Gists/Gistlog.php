<?php

namespace App\Gists;

use App\Authors\Author;
use App\ContentParser\ContentParserFacade as ContentParser;
use App\Gists\File;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class Gistlog
{
    public $id;
    public $title;
    public $content;
    public $language;
    public $author;
    public $avatarUrl;
    public $link;
    public $config;
    public $files;
    public $commentCount;

    private $public;

    /**
     * @var Carbon
     */
    public $createdAt;

    /**
     * @var Carbon
     */
    public $updatedAt;

    /**
     * @param array|ArrayAccess $githubGist
     * @return Gistlog
     */
    public static function fromGitHub($githubGist): Gistlog
    {
        $gistlog = new self();

        $files = File::multipleFromGitHub($githubGist['files']);
        $postFile = $files->getPostFile();

        $gistlog->id = $githubGist['id'];
        $gistlog->title = $githubGist['description'];

        $gistlog->content = $postFile->content;
        $gistlog->language = $postFile->language;
        $gistlog->link = $githubGist['html_url'];
        $gistlog->public = $githubGist['public'];
        $gistlog->createdAt = Carbon::parse($githubGist['created_at']);
        $gistlog->updatedAt = Carbon::parse($githubGist['updated_at']);
        $gistlog->commentCount = $githubGist['comments'];

        if (isset($githubGist['owner'])) {
            $gistlog->author = $githubGist['owner']['login'];
            $gistlog->avatarUrl = $githubGist['owner']['avatar_url'];
        } else {
            $gistlog->author = Author::ANONYMOUS_USERNAME;
            $gistlog->avatarUrl = Author::ANONYMOUS_AVATAR_URL;
        }

        $gistlog->config = GistConfig::fromGitHub($githubGist);
        $gistlog->files = $gistlog->showFiles() ? $files->getAdditionalFiles() : new FileCollection([]);

        return $gistlog;
    }

    public function renderHtml(): string
    {
        if ($this->language === 'Markdown') {
            return $this->renderMarkdown();
        }

        return '<pre><code>' . htmlspecialchars($this->content) . "\n</code></pre>";
    }

    public function hasPublishedOnDate(): bool
    {
        return ! is_null($this->config['published_on']);
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    /**
     * @return bool
     */
    public function isSecret()
    {
        return ! $this->isPublic();
    }

    /**
     * @return bool
     */
    public function isAnonymous()
    {
        return $this->author === Author::ANONYMOUS_USERNAME;
    }

    public function formattedPublishedOnDate()
    {
        return $this->config['published_on']->diffForHumans();
    }

    public function getPreview()
    {
        if (! is_null($this->config['preview'])) {
            return $this->config['preview'];
        }

        $body = strip_tags($this->renderHtml());

        if (strlen($body) < 200) {
            return $body;
        }

        return substr($body, 0, strpos($body, ' ', 200));
    }

    public function showFiles()
    {
        return $this->config['include_files'];
    }

    public function localUrl()
    {
        return route('gists.show', [
            'username' => $this->author,
            'gistId' => $this->id,
        ]);
    }

    private function renderMarkdown()
    {
        if ($this->updatedAt == Cache::get('markdown.updated_at.' . $this->id)) {
            return Cache::get('markdown.' . $this->id);
        }

        $markdown = ContentParser::transform($this->content);

        Cache::forever('markdown.' . $this->id, $markdown);
        Cache::forever('markdown.updated_at.' . $this->id, $this->updatedAt);

        return $markdown;
    }
}
