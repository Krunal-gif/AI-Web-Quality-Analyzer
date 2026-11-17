@php
    use App\Analyzer\ReportView as R;
    $before = $data['scores_before'];
    $after = $data['after'];
    $afterScores = $after['scores'] ?? null;
    $st = $data['statuses'];
    $title = $after ? 'Final Fix Report' : 'Analysis Report';
    $stats = [['Detected', $before['total']],
        ['Approved', ($st['approved'] ?? 0) + ($st['applied'] ?? 0) + ($st['conflict'] ?? 0)],
        ['Rejected', $st['rejected'] ?? 0], ['Pending', $st['pending'] ?? 0]];
    if ($after) {
        array_push($stats, ['Applied', $after['applied']], ['Verified fixed', $after['resolved']], ['Remaining', $after['scores']['total']]);
    }
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} — {{ $data['target']['label'] }}</title>
<style>
:root{--bg:#f6f7f9;--card:#fff;--ink:#16181d;--muted:#5d6472;--line:#e3e6eb;--del:#ffe1e1;--ins:#d9f7e3;
--crit:#b3261e;--high:#d9480f;--med:#b7791f;--low:#2f6fd6;--info:#6b7280;--ok:#1a7f45}
@media (prefers-color-scheme:dark){:root{--bg:#111318;--card:#1a1d24;--ink:#e8eaee;--muted:#9aa2b1;--line:#2b303a;--del:#4a1f22;--ins:#173a26}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
main{max-width:1100px;margin:0 auto;padding:32px 16px 64px}
.eyebrow{color:var(--muted);text-transform:uppercase;letter-spacing:.08em;font-size:12px;margin:0}
h1{margin:4px 0 4px;font-size:26px;word-break:break-all}.meta{color:var(--muted);margin:0 0 24px}
.scores{display:grid;grid-template-columns:220px 1fr;gap:16px;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px}
.big{display:flex;flex-direction:column;align-items:flex-start;gap:2px}.big span{color:var(--muted);font-size:13px}
.big div{display:flex;align-items:baseline;gap:8px}.big b{font-size:52px;line-height:1}.big i{font-style:normal;color:var(--muted);font-size:24px}.big .after{color:var(--ok)}
table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:6px 8px;border-bottom:1px solid var(--line)}
thead th{color:var(--muted);font-weight:500;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.delta.up{color:var(--ok);font-weight:600}
.stats{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0 8px}.stat{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:10px 16px;min-width:110px}
.stat b{display:block;font-size:22px}.stat span{color:var(--muted);font-size:13px}
section h3{margin:32px 0 2px;font-size:19px}.count{background:var(--line);border-radius:99px;padding:1px 9px;font-size:13px;vertical-align:middle}
.desc{color:var(--muted);margin:0 0 12px}
.issue{background:var(--card);border:1px solid var(--line);border-left:4px solid var(--info);border-radius:10px;padding:14px 16px;margin:12px 0;break-inside:avoid}
.sev-critical{border-left-color:var(--crit)}.sev-high{border-left-color:var(--high)}.sev-medium{border-left-color:var(--med)}.sev-low{border-left-color:var(--low)}
.issue header{display:flex;flex-wrap:wrap;align-items:center;gap:8px}.issue h4{margin:0;font-size:16px;flex:1 1 220px}
.sev{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:2px 7px;border-radius:5px;color:#fff;background:var(--info)}
.sev-critical .sev,.sev.sev-critical{background:var(--crit)}.sev-high .sev,.sev.sev-high{background:var(--high)}.sev-medium .sev,.sev.sev-medium{background:var(--med)}.sev-low .sev,.sev.sev-low{background:var(--low)}
.cat{font-size:12px;color:var(--muted)}.loc{font:12px ui-monospace,Menlo,Consolas,monospace;color:var(--muted)}
.chip{font-size:12px;border-radius:99px;padding:1px 9px;border:1px solid var(--line)}.chip.ok{color:var(--ok);border-color:var(--ok)}.chip.warn{color:var(--high);border-color:var(--high)}
.st-applied{background:var(--ins)}.st-rejected{opacity:.7}.st-conflict,.st-failed{color:var(--crit);border-color:var(--crit)}
dl{display:grid;grid-template-columns:150px 1fr;gap:4px 12px;margin:12px 0}dt{color:var(--muted);font-size:13px}dt small{display:block;font-size:11px}dd{margin:0}
.ba{display:grid;grid-template-columns:1fr 1fr;gap:12px}h5{margin:0 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}h5 small{text-transform:none;letter-spacing:0}
.code{border:1px solid var(--line);border-radius:8px;overflow:hidden}.ln{font-size:11px;color:var(--muted);padding:3px 8px;border-bottom:1px solid var(--line)}
pre{margin:0;padding:8px;font:12.5px/1.5 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-word;max-height:320px;overflow:auto}
mark.del{background:var(--del);color:inherit;text-decoration:line-through;text-decoration-color:rgba(179,38,30,.5)}mark.ins{background:var(--ins);color:inherit}
.caret{background:var(--del);border-radius:4px;padding:0 4px;font-size:11px;color:var(--muted)}
.nocode{color:var(--muted);font-size:13px;padding:8px;border:1px dashed var(--line);border-radius:8px}
.note{font-size:13px;color:var(--med);margin:8px 0 0}
.files code,.rem .loc{word-break:break-all}footer{margin-top:40px;color:var(--muted);font-size:13px}
@media (max-width:760px){.scores,.ba{grid-template-columns:1fr}dl{grid-template-columns:1fr}.rem{display:block;overflow-x:auto}}
@media print{body{background:#fff}.issue{border-color:#ccc}pre{max-height:none}}
.big div{display:flex;align-items:baseline;gap:8px}
</style></head>
<body><main>
<header class="top">
  <p class="eyebrow">AI Web Quality Analyzer · {{ $title }}</p>
  <h1>{{ $data['target']['label'] }}</h1>
  <p class="meta">{{ strtoupper($data['target']['kind']) }} · {{ count($data['target']['files']) }} file(s) analysed · {{ $data['created'] }} · AI model: {{ $data['model'] ?: 'not used' }}</p>
</header>

<section class="scores">
  <div class="big"><span>Overall score</span>
    <div><b>{{ $before['overall'] }}</b>@if($afterScores)<i>→</i><b class="after">{{ $afterScores['overall'] }}</b>@endif</div></div>
  <table><thead><tr><th>Category</th><th>Issues</th><th>Score</th>
    @if($afterScores)<th>Issues after</th><th>Score after</th><th>Δ</th>@endif</tr></thead><tbody>
  @foreach(App\Analyzer\Issue::CATEGORIES as $cat)
    @php $b = $before['categories'][$cat]; @endphp
    <tr><th>{{ $cat }}</th><td>{{ $before['counts'][$cat] }}</td><td><b>{{ $b }}</b></td>
    @if($afterScores)
      @php $a = $afterScores['categories'][$cat]; @endphp
      <td>{{ $afterScores['counts'][$cat] }}</td><td><b>{{ $a }}</b></td>
      <td class="delta {{ $a > $b ? 'up' : '' }}">{{ $a === $b ? '–' : ($a > $b ? '+' : '').($a - $b) }}</td>
    @endif</tr>
  @endforeach
  </tbody></table>
</section>

<section class="stats">
  @foreach($stats as [$k, $v])<div class="stat"><b>{{ $v }}</b><span>{{ $k }}</span></div>@endforeach
</section>

@php $site = $data['target']['site'] ?? null; @endphp
@if($prof = $site['profile'] ?? null)
@php
    $names = fn ($list) => implode(', ', array_map(fn ($t) => trim($t['name'].' '.($t['version'] ?? '')), $list));
    $h = $prof['hosting'];
@endphp
<section><h3>Site profile</h3><p class="desc">What {{ parse_url($prof['url'], PHP_URL_HOST) }} is built with and where it is hosted.</p>
  <dl>
    <dt>Platform</dt><dd>{{ $names($prof['platform']) ?: 'Not identified' }}</dd>
    <dt>Server language</dt><dd>{{ implode(', ', $prof['languages']['server']) ?: 'Not visible' }}</dd>
    <dt>Client side</dt><dd>{{ implode(', ', $prof['languages']['client']) }}</dd>
    @if($prof['frontend'])<dt>Libraries</dt><dd>{{ $names($prof['frontend']) }}</dd>@endif
    <dt>Web server</dt><dd>{{ $prof['server'] ?: ($prof['cdn'] ? 'Hidden by '.$prof['cdn'] : 'Not visible') }}</dd>
    @if($prof['cdn'])<dt>CDN</dt><dd>{{ $prof['cdn'] }}</dd>@endif
    @if($h)
      <dt>Hosted in</dt><dd>@if($h['error']){{ $h['error'] }}@else{{ implode(', ', array_unique(array_filter([$h['city'], $h['region'], $h['country']]))) }} — {{ $h['provider'] }} @if($h['asn'])({{ $h['asn'] }})@endif · <code>{{ $h['ip'] }}</code>
        @if($h['behind_cdn'])<br><small>This is {{ $prof['cdn'] }}'s nearest server; the site's own server and country are hidden behind the CDN.</small>@endif @endif</dd>
    @endif
  </dl>
</section>
@endif
@if($site && count($site['pages'] ?? []) > 1)
<section><h3>Pages <span class="count">{{ count($site['pages']) }}</span></h3>
  <p class="desc">@if($site['sitemap_url'] ?? null)Read from the sitemap <code>{{ $site['sitemap_url'] }}</code> ({{ $site['sitemap_total'] }} page(s) listed), then analysed one by one.@else No sitemap found — pages were found by following links.@endif</p>
  <table class="rem"><thead><tr><th>Page</th><th>HTTP</th><th>Found via</th><th>Issues</th>
    @foreach(array_keys(R::SEV_LABEL) as $sev)<th>{{ R::SEV_LABEL[$sev] }}</th>@endforeach</tr></thead><tbody>
  @foreach($site['pages'] as $pg)
    <tr><td class="loc">{{ $pg['url'] }}</td><td>{{ $pg['status'] ?: 'failed' }}</td><td>{{ $pg['source'] }}</td><td><b>{{ $pg['issues'] }}</b></td>
      @foreach(array_keys(R::SEV_LABEL) as $sev)<td>{{ $pg['counts'][$sev] ?: '' }}</td>@endforeach</tr>
  @endforeach
  </tbody></table>
</section>
@endif

@foreach(R::groups($data['issues']) as $g)
<section><h3>{{ $g['label'] }} <span class="count">{{ count($g['group']) }}</span></h3><p class="desc">{{ $g['desc'] }}</p>
  @foreach($g['group'] as $i)
    @php
        $ai = $i['ai'] ?? [];
        $why = ($ai['why'] ?? '') ?: $i['why'];
        $fix = ($ai['fix'] ?? '') ?: $i['how'];
        $src = ['rule' => 'rule-based', 'ai' => 'AI-generated', 'user' => 'edited by reviewer'][$i['proposed_source']] ?? '';
    @endphp
    <article class="issue sev-{{ $i['severity'] }}">
      <header>
        <span class="sev">{{ R::SEV_LABEL[$i['severity']] }}</span><span class="cat">{{ $i['category'] }}</span>
        <h4>{{ $i['title'] }}</h4><span class="loc">{{ $i['file'] }}{{ $i['line'] ? ':'.$i['line'] : '' }}</span>
        <span class="chip st-{{ $i['status'] }}">{{ $i['status'] }}</span>
        @if($i['resolved'] === true)<span class="chip ok">✓ verified by re-scan</span>
        @elseif($i['resolved'] === false)<span class="chip warn">still detected after fix</span>@endif
      </header>
      <dl>
        <dt>Problem</dt><dd>{{ $i['message'] }}</dd>
        <dt>Why it matters <small>{{ ($ai['why'] ?? '') ? 'AI · '.($ai['model'] ?? '') : 'Rule' }}</small></dt><dd>{{ $why }}</dd>
        <dt>Fix</dt><dd>{{ $fix }}</dd>
      </dl>
      <div class="ba">
        <div><h5>Before</h5>{!! R::code($i['context'], null, $i['insert']) !!}</div>
        @if($i['editable'] && $i['proposed'] !== null)
          <div><h5>After <small>{{ $src }}</small></h5>{!! R::code($i['context'], $i['proposed'], $i['insert']) !!}</div>
        @elseif($i['editable'])
          <div><h5>After</h5><div class="nocode">No fix proposed yet</div></div>
        @endif
      </div>
      @if($i['fix_note'] && $i['proposed_source'] === 'rule')<p class="note">⚠ {{ $i['fix_note'] }}</p>@endif
      @if($i['result_note'])<p class="note">{{ $i['result_note'] }}</p>@endif
    </article>
  @endforeach
</section>
@endforeach

@if($after)
<section><h3>Files changed</h3>
  <ul class="files">
  @forelse($after['files'] as $f)
    <li><code>{{ $f['file'] }}</code> — {{ $f['fixes'] }} fix(es) → <code>{{ $f['written_to'] }}</code></li>
  @empty <li>No files changed.</li> @endforelse
  </ul>
  @if($after['patch'])<p>Unified diff: <code>{{ $after['patch'] }}</code></p>@endif
  @if($after['backup_dir'])<p>Original files backed up to <code>{{ $after['backup_dir'] }}</code></p>@endif
</section>
<section><h3>Issues remaining after re-scan <span class="count">{{ count($after['remaining']) }}</span></h3>
  <table class="rem"><thead><tr><th>Severity</th><th>Category</th><th>Issue</th><th>Detail</th><th>Location</th></tr></thead><tbody>
  @foreach($after['remaining'] as $r)
    <tr><td><span class="sev sev-{{ $r['severity'] }}">{{ R::SEV_LABEL[$r['severity']] }}</span></td><td>{{ $r['category'] }}</td>
      <td>{{ $r['title'] }}</td><td>{{ $r['message'] }}</td><td class="loc">{{ $r['file'] }}{{ $r['line'] ? ':'.$r['line'] : '' }}</td></tr>
  @endforeach
  </tbody></table>
</section>
@endif

<footer>Generated by AI Web Quality Analyzer {{ $data['version'] }}. Static analysis + {{ $data['model'] ? 'local LLM via Ollama' : 'rule-based explanations' }}. Every change was reviewed and approved by a person before it was applied.</footer>
</main></body></html>
