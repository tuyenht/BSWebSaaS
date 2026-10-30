<?php

use App\Modules\Shared\Support\BoundedRead;
use App\Modules\Shared\Support\VietnameseFold;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('stops a read that runs past its limit with a timeout the caller can recognise', function (): void {
    try {
        app(BoundedRead::class)->run(fn () => DB::select('select pg_sleep(0.3)'), 50);
        $this->fail('the read was not stopped');
    } catch (QueryException $e) {
        expect(BoundedRead::timedOut($e))->toBeTrue();
    }

    expect(DB::transactionLevel())->toBe(0);
});

it('keeps the limit inside its own transaction, never on the pooled connection', function (): void {
    $before = DB::selectOne('show statement_timeout')->statement_timeout;

    $inside = app(BoundedRead::class)->run(fn () => DB::selectOne('show statement_timeout')->statement_timeout, 1234);

    expect($inside)->toBe('1234ms')
        ->and(DB::selectOne('show statement_timeout')->statement_timeout)->toBe($before);
});

it('folds in SQL exactly as in PHP', function (): void {
    $text = 'Nguyễn Đức ỸỶỰ Trình Đơn Ấp Ổ';
    $sql = DB::selectOne('select '.VietnameseFold::sql('?::text').' as folded', [$text])->folded;

    expect($sql)->toBe(VietnameseFold::text($text));
});
