<?php


namespace App\Console\Commands;

use App\Models\Course;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\DomCrawler\Crawler;
use App\Models\ScrapedData; // Ensure this model exists

class ScrapeCoursesCommand extends Command
{


    protected $signature = 'scrape:courses';
    protected $description = 'Scrape course data from a given URL and store it in the database';
    public function __construct()
    {
        parent::__construct();
    }
    public function handle()
    {
        $response = Storage::disk('public')->get('Classes.html');
        //   $html = file_get_contents($response);
        $crawler = new Crawler($response);
        $this->scrapeData($crawler);
    }
    private function scrapeData(Crawler $crawler)
    {
        $courseName = '';
        $courseID = '';

        $table = $crawler->filter('tbody')->first();

        $table->filter('tr')->each(function ($row) use (&$courseName, &$courseID) {
            $row->filter('td')->each(function ($column, $j) use (&$courseName, &$courseID) {
                if ($j === 1) {
                    $courseName = trim($column->text(), " ");
                }
                if ($j === 7) {
                    $column->filter('a')->each(function ($link, $k) use (&$courseID, $courseName) {
                        if ($k === 0) {
                            $courseID = str($link->attr('href'))
                                ->after('&class=')
                                ->toString();
                            Course::updateOrCreate(
                                ['course_id' => $courseID],
                                ['course_name' => $courseName]
                            );
                        }
                    });
                }
            });
        });
    }
}
