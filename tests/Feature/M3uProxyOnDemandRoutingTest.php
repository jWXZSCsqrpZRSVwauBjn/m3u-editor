<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    config([
        'proxy.m3u_proxy_host' => 'http://proxy.test',
        'proxy.m3u_proxy_port' => 8085,
        'proxy.m3u_proxy_token' => 'fixture-token',
    ]);
});

test('on-demand playback delegates content detection to the proxy for new and reused streams', function (string $type, bool $reuse, string $source) {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'profiles_enabled' => $type === 'episode' && $reuse,
        'enable_proxy' => true,
        'available_streams' => 0,
        'xtream' => false,
    ]);
    Redis::shouldReceive('exists')->andReturn($reuse ? 1 : 0);

    if ($type === 'episode') {
        $series = Series::factory()->for($user)->for($playlist)->create();
        $record = Episode::factory()->for($user)->for($playlist)->for($series)->create(['url' => $source]);
    } else {
        $record = Channel::factory()->for($user)->for($playlist)->create(['url' => $source, 'is_vod' => true]);
    }

    Http::fake([
        'http://proxy.test:8085/streams/by-metadata*' => Http::response([
            'matching_streams' => $reuse ? [[
                'stream_id' => 'fixture-stream',
                'client_count' => 1,
                'metadata' => [
                    'original_'.$type.'_id' => $record->id,
                    'original_playlist_uuid' => $playlist->uuid,
                ],
            ]] : [],
            'total_matching' => $reuse ? 1 : 0,
            'total_clients' => $reuse ? 1 : 0,
        ]),
        'http://proxy.test:8085/streams' => Http::response(['stream_id' => 'fixture-stream']),
    ]);

    $service = app(M3uProxyService::class);
    $url = $type === 'episode'
        ? $service->getEpisodeUrl($playlist, $record, username: 'fixture-viewer')
        : $service->getChannelUrl($playlist, $record, username: 'fixture-viewer');

    expect($url)->toContain(str_ends_with($source, '.mp4')
        ? '/stream/fixture-stream?username=fixture-viewer'
        : '/hls/fixture-stream/playlist.m3u8?auto=true&username=fixture-viewer');
    Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'http://backend.test/'));
    if ($reuse) {
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    } else {
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request['url'] === $source
            && $request->hasHeader('X-API-Token', 'fixture-token'));
    }
})->with(['channel', 'episode'])->with([false, true])->with([
    'HLS or misleading extension' => 'http://backend.test/video.m3u8',
    'video file' => 'http://backend.test/video.mp4',
    'opaque source' => 'http://backend.test/playback',
]);
