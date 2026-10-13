<?php

beforeEach(function () {
    $this->files = [];
    $this->day = fn (int $ago, int $bytes) => file_put_contents(
        $this->files[] = storage_path('logs/jot-disk-'.now()->subDays($ago)->format('Y-m-d').'.log'),
        str_repeat('x', $bytes),
    );
    config(['logging.channels.disk' => ['driver' => 'daily', 'path' => storage_path('logs/jot-disk.log'), 'days' => 7]]);
    config(['jot.outputs' => [['channel' => 'disk']]]);
});

afterEach(function () {
    array_map('unlink', array_filter($this->files, 'is_file'));
});

it('averages the past days and says retention keeps the disk from filling', function () {
    ($this->day)(1, 1000);
    ($this->day)(2, 3000);
    ($this->day)(0, 99999);

    $this->artisan('jot:disk')
        ->expectsOutputToContain('7 days')
        ->expectsOutputToContain('3 files')
        ->expectsOutputToContain('2 KB a day')
        ->expectsOutputToContain('14 KB')
        ->expectsOutputToContain('never')
        ->assertSuccessful();
});

it('counts the days until full when every file is kept', function () {
    config(['logging.channels.disk.days' => 0]);
    ($this->day)(1, 1000);

    $days = (int) floor(disk_free_space(storage_path('logs')) / 1000);

    $this->artisan('jot:disk')
        ->expectsOutputToContain('every file kept')
        ->expectsOutputToContain(number_format($days, 0, '', '').' days')
        ->assertSuccessful();
});

it('skips outputs that are not files', function () {
    config(['jot.outputs' => [['channel' => 'stderr']]]);

    $this->artisan('jot:disk')->expectsOutputToContain('not a file, skipped')->assertSuccessful();
});
