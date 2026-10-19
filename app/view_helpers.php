<?php
declare(strict_types=1);
function pillAvatar(?array $actor, int $size=42): string {
    $name=$actor['name']??$actor['username']??'?'; $img=$actor['avatar']??'';
    $style='width:'.$size.'px;height:'.$size.'px;';
    if ($img && ($img[0]==='/' || str_starts_with($img,cfgUrl()) || isSafePublicUrl($img)))
        return '<img class="avatar" width="'.$size.'" height="'.$size.'" src="'.escape($img).'" alt="" loading="lazy" style="'.$style.'">';
    return '<span class="avatar-placeholder" style="'.$style.'font-size:'.round($size*.4).'px">'.escape(mb_strtoupper(mb_substr($name,0,1))).'</span>';
}
function actorLabel(string $id): string {
    if ($id===actorUrl()) return localHandle();
    $a=queryOne('SELECT handle FROM remote_actors WHERE id=?',[$id]);
    return ($a['handle']??'') ?: $id;
}
function uiIcon(string $key): string {
    $paths=[
       'home'=>'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
       'profile'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
       'following'=>'<circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 8h6m-3-3v6"/>',
       'alerts'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 10h18c0-2-3-3-3-10M10 22h4"/>',
       'settings'=>'<path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/><path d="M19.4 15a1.8 1.8 0 0 0 .4 2l-2 2a1.8 1.8 0 0 0-2-.4l-.6.3-.3 2.1h-3l-.3-2.1-.6-.3a1.8 1.8 0 0 0-2 .4l-2-2a1.8 1.8 0 0 0 .4-2l-.3-.6L5 14v-3l2.1-.3.3-.6a1.8 1.8 0 0 0-.4-2l2-2a1.8 1.8 0 0 0 2 .4l.6-.3L12 4h3l.3 2.1.6.3a1.8 1.8 0 0 0 2-.4l2 2a1.8 1.8 0 0 0-.4 2l.3.6L22 11v3l-2.1.3z" transform="translate(-1 -1) scale(.94)"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$key]??$paths['home']).'</svg>';
}
