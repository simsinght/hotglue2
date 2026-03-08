<?php

/**
 *	module_music.inc.php
 *	Music player module for hotglue with iOS Media Session API
 *
 *	Copyright Gottfried Haider, Danja Vasiliev 2010.
 *	This source code is licensed under the GNU General Public License.
 *	See the file COPYING for more details.
 */

@require_once('config.inc.php');
require_once('common.inc.php');
require_once('html.inc.php');
require_once('modules.inc.php');


/**
 *	implements render_object
 *
 *	Creates the music player object element
 */
function music_render_object($args)
{
	$obj = $args['obj'];
	if (!isset($obj['type']) || ($obj['type'] != 'music' && $obj['type'] != 'music-lyrics')) {
		return false;
	}

	// Create container element
	$e = elem('div');
	elem_attr($e, 'id', $obj['name']);
	if ($obj['type'] == 'music-lyrics') {
		elem_add_class($e, 'music-lyrics-screen');
	} else {
		elem_add_class($e, 'music-player');
	}
	elem_add_class($e, 'resizable');
	elem_add_class($e, 'object');

	// Invoke hooks to build the element
	invoke_hook_first('alter_render_early', 'music', array('obj'=>$obj, 'elem'=>&$e, 'edit'=>$args['edit']));
	$html = elem_finalize($e);
	invoke_hook_last('alter_render_late', 'music', array('obj'=>$obj, 'html'=>&$html, 'elem'=>$e, 'edit'=>$args['edit']));

	return $html;
}


/**
 *	implements alter_render_early
 *
 *	Renders the music player UI inside the object
 */
function music_alter_render_early($args)
{
	$elem = &$args['elem'];
	$obj = $args['obj'];

	// Handle lyrics screen object
	if (elem_has_class($elem, 'music-lyrics-screen')) {
		if (elem_css($elem, 'background') === NULL && elem_css($elem, 'background-color') === NULL) {
			elem_css($elem, 'background', 'rgba(0, 0, 0, 1)');
		}
		if (elem_css($elem, 'border-radius') === NULL) {
			elem_css($elem, 'border-radius', '15px');
		}
		elem_attr($elem, 'data-music-parent', $obj['music-parent'] ?? '');

		// Font size (pixels)
		$fontSize = intval($obj['music-lyrics-fontsize'] ?? 20);
		if ($fontSize < 8) $fontSize = 20;
		elem_attr($elem, 'data-music-lrc-fontsize', $fontSize);

		$inner = elem('div');
		elem_add_class($inner, 'music-lyrics-inner');
		elem_css($inner, 'font-size', $fontSize . 'px');
		if ($args['edit']) {
			elem_append($inner, '<div class="music-lrc-empty">Lyrics screen (' . $fontSize . 'px)</div>');
		}
		elem_append($elem, $inner);
		return true;
	}

	if (!elem_has_class($elem, 'music-player')) {
		return false;
	}

	// Get tracks data
	$tracks = json_decode($obj['music-tracks'] ?? '[]', true);
	if (!is_array($tracks)) {
		$tracks = array();
	}

	$current = intval($obj['music-current'] ?? 0);
	if ($current < 0 || $current >= count($tracks)) {
		$current = 0;
	}

	// Get page name for building URLs
	$a = expl('.', $obj['name']);
	$pagename = $a[0];

	// Build content URL base
	if (CONTENT_DIR[0] === '/') {
		$content_relative = basename(CONTENT_DIR);
	} else {
		$content_relative = CONTENT_DIR;
	}
	$base = base_url() . $content_relative . '/' . $pagename . '/shared/';

	// Build tracks JSON with full URLs for JS
	$tracksJs = array();
	foreach ($tracks as $t) {
		$hasLrc = !empty($t['lrcFile']) || !empty($t['lrc']);
		$lrcUrl = '';
		if (!empty($t['lrcFile'])) {
			$lrcUrl = $base . rawurlencode($t['lrcFile']);
		}

		$tracksJs[] = array(
			'title' => $t['title'] ?? 'Untitled',
			'artist' => $t['artist'] ?? 'Unknown',
			'audioFile' => $t['audioFile'] ?? '',
			'coverFile' => $t['coverFile'] ?? '',
			'lrcFile' => $t['lrcFile'] ?? '',
			'lrcUrl' => $lrcUrl,
			'hasLrc' => $hasLrc,
			'lrcOffset' => floatval($t['lrcOffset'] ?? 0),
			'karaokeAudioFile' => $t['karaokeAudioFile'] ?? '',
			'lyricsVideoFile' => $t['lyricsVideoFile'] ?? '',
			'audioUrl' => !empty($t['audioFile']) ? $base . rawurlencode($t['audioFile']) : '',
			'coverUrl' => !empty($t['coverFile']) ? $base . rawurlencode($t['coverFile']) : '',
			'karaokeAudioUrl' => !empty($t['karaokeAudioFile']) ? $base . rawurlencode($t['karaokeAudioFile']) : '',
			'lyricsVideoUrl' => !empty($t['lyricsVideoFile']) ? $base . rawurlencode($t['lyricsVideoFile']) : ''
		);
	}

	elem_attr($elem, 'data-music-tracks', json_encode($tracksJs));
	elem_attr($elem, 'data-music-current', $current);

	// Karaoke API URL for live sing-along
	if (!empty($obj['music-karaoke-api'])) {
		elem_attr($elem, 'data-karaoke-api', $obj['music-karaoke-api']);
	}

	// Link to lyrics screen if exists
	if (!empty($obj['music-lyrics-obj'])) {
		elem_attr($elem, 'data-music-lyrics-obj', $obj['music-lyrics-obj']);
	}

	// Default inline styles for the dark gradient look
	if (elem_css($elem, 'background') === NULL && elem_css($elem, 'background-color') === NULL) {
		elem_css($elem, 'background', 'linear-gradient(135deg, #2c2c2c, #1a1a1a)');
	}
	if (elem_css($elem, 'border-radius') === NULL) {
		elem_css($elem, 'border-radius', '15px');
	}
	if (elem_css($elem, 'padding') === NULL) {
		elem_css($elem, 'padding', '20px');
	}
	if (elem_css($elem, 'box-shadow') === NULL) {
		elem_css($elem, 'box-shadow', '0 8px 32px rgba(0,0,0,0.5)');
	}

	// Size preset class
	$size = $obj['music-size'] ?? 'md';
	if (!in_array($size, array('sm', 'md', 'lg', 'vt'))) {
		$size = 'md';
	}
	elem_add_class($elem, 'music-size-' . $size);
	elem_attr($elem, 'data-music-size', $size);

	// Determine initial display values
	$coverUrl = '';
	$title = 'No tracks';
	$artist = '';
	if (!empty($tracksJs) && isset($tracksJs[$current])) {
		$coverUrl = $tracksJs[$current]['coverUrl'];
		$title = htmlspecialchars($tracksJs[$current]['title']);
		$artist = htmlspecialchars($tracksJs[$current]['artist']);
	}

	// Top row: album art + info side by side
	$topDiv = elem('div');
	elem_add_class($topDiv, 'music-top');

	// Album art
	$artDiv = elem('div');
	elem_add_class($artDiv, 'music-album-art');
	$img = elem('img');
	elem_add_class($img, 'music-cover-img');
	elem_attr($img, 'src', $coverUrl);
	elem_attr($img, 'draggable', 'false');
	elem_append($artDiv, $img);
	elem_append($topDiv, $artDiv);

	// Track info + audio scrubber (right of album art)
	$infoDiv = elem('div');
	elem_add_class($infoDiv, 'music-track-info');

	$titleDiv = elem('div');
	elem_add_class($titleDiv, 'music-title');
	elem_append($titleDiv, $title);
	elem_append($infoDiv, $titleDiv);

	$artistDiv = elem('div');
	elem_add_class($artistDiv, 'music-artist');
	elem_append($artistDiv, $artist);
	elem_append($infoDiv, $artistDiv);

	// Custom progress bar (replaces native audio controls to avoid iOS clipping)
	$progressWrap = elem('div');
	elem_add_class($progressWrap, 'music-progress-wrap');

	$progressBar = elem('div');
	elem_add_class($progressBar, 'music-progress-bar');

	$progressFill = elem('div');
	elem_add_class($progressFill, 'music-progress-fill');
	elem_append($progressBar, $progressFill);
	elem_append($progressWrap, $progressBar);

	$timeRow = elem('div');
	elem_add_class($timeRow, 'music-time-row');

	$timeElapsed = elem('span');
	elem_add_class($timeElapsed, 'music-time-elapsed');
	elem_append($timeElapsed, '0:00');
	elem_append($timeRow, $timeElapsed);

	$timeRemaining = elem('span');
	elem_add_class($timeRemaining, 'music-time-remaining');
	elem_append($timeRemaining, '-0:00');
	elem_append($timeRow, $timeRemaining);

	elem_append($progressWrap, $timeRow);

	elem_append($infoDiv, $progressWrap);

	// Hidden audio element (no native controls)
	$audio = elem('audio');
	elem_add_class($audio, 'music-audio');
	elem_attr($audio, 'preload', 'none');

	$srcMp4 = elem('source');
	elem_add_class($srcMp4, 'music-source-mp4');
	elem_attr($srcMp4, 'type', 'audio/mp4');
	if (!empty($tracksJs) && isset($tracksJs[$current]) && !empty($tracksJs[$current]['audioUrl'])) {
		elem_attr($srcMp4, 'src', $tracksJs[$current]['audioUrl']);
	}
	elem_append($audio, $srcMp4);

	$srcMpeg = elem('source');
	elem_add_class($srcMpeg, 'music-source-mpeg');
	elem_attr($srcMpeg, 'type', 'audio/mpeg');
	if (!empty($tracksJs) && isset($tracksJs[$current]) && !empty($tracksJs[$current]['audioUrl'])) {
		elem_attr($srcMpeg, 'src', $tracksJs[$current]['audioUrl']);
	}
	elem_append($audio, $srcMpeg);

	elem_append($elem, $audio);
	elem_append($topDiv, $infoDiv);
	elem_append($elem, $topDiv);

	// Controls row: prev-page / prev / play / next / next-page
	$controlsDiv = elem('div');
	elem_add_class($controlsDiv, 'music-controls');

	// Page skip buttons — always render both if either is set, hide missing one for centering
	$prevPage = $obj['music-prev-page'] ?? '';
	$nextPage = $obj['music-next-page'] ?? '';
	$hasAnyPageLink = !empty($prevPage) || !empty($nextPage);

	if ($hasAnyPageLink) {
		$prevPageBtn = elem('button');
		elem_add_class($prevPageBtn, 'music-prev-page');
		if (!empty($prevPage)) {
			$prevPageUrl = $prevPage;
			if (strpos($prevPageUrl, 'http') !== 0 && strpos($prevPageUrl, '/') !== 0) {
				$prevPageUrl = base_url() . $prevPageUrl;
			}
			elem_attr($prevPageBtn, 'data-page', $prevPageUrl);
		} else {
			elem_css($prevPageBtn, 'visibility', 'hidden');
		}
		elem_append($prevPageBtn, '<svg width="22" height="22" viewBox="0 0 22 22"><rect x="1" y="4" width="3" height="14" rx="1" fill="white"/><path d="M18 4L8 11l10 7z" fill="white"/></svg>');
		elem_append($controlsDiv, $prevPageBtn);
	}

	$prevBtn = elem('button');
	elem_add_class($prevBtn, 'music-prev');
	elem_append($prevBtn, '<svg width="28" height="28" viewBox="0 0 28 28"><path d="M16 4L4 14l12 10z" fill="white"/><path d="M27 4L15 14l12 10z" fill="white"/></svg>');
	elem_append($controlsDiv, $prevBtn);

	$playBtn = elem('button');
	elem_add_class($playBtn, 'music-play-pause');
	elem_append($playBtn, '<svg class="music-icon-play" width="36" height="36" viewBox="0 0 36 36"><path d="M10 6.5a1.5 1.5 0 0 1 2.3-1.3l18 11.5a1.5 1.5 0 0 1 0 2.6l-18 11.5A1.5 1.5 0 0 1 10 29.5z" fill="white"/></svg><svg class="music-icon-pause" style="display:none" width="36" height="36" viewBox="0 0 36 36"><rect x="8" y="6" width="6" height="24" rx="1.5" fill="white"/><rect x="22" y="6" width="6" height="24" rx="1.5" fill="white"/></svg>');
	elem_append($controlsDiv, $playBtn);

	$nextBtn = elem('button');
	elem_add_class($nextBtn, 'music-next');
	elem_append($nextBtn, '<svg width="28" height="28" viewBox="0 0 28 28"><path d="M1 4l12 10L1 24z" fill="white"/><path d="M12 4l12 10-12 10z" fill="white"/></svg>');
	elem_append($controlsDiv, $nextBtn);

	if ($hasAnyPageLink) {
		$nextPageBtn = elem('button');
		elem_add_class($nextPageBtn, 'music-next-page');
		if (!empty($nextPage)) {
			$nextPageUrl = $nextPage;
			if (strpos($nextPageUrl, 'http') !== 0 && strpos($nextPageUrl, '/') !== 0) {
				$nextPageUrl = base_url() . $nextPageUrl;
			}
			elem_attr($nextPageBtn, 'data-page', $nextPageUrl);
		} else {
			elem_css($nextPageBtn, 'visibility', 'hidden');
		}
		elem_append($nextPageBtn, '<svg width="22" height="22" viewBox="0 0 22 22"><path d="M4 4l10 7-10 7z" fill="white"/><rect x="18" y="4" width="3" height="14" rx="1" fill="white"/></svg>');
		elem_append($controlsDiv, $nextPageBtn);
	}

	elem_append($elem, $controlsDiv);

	// Toggles row: lyrics / instrumental
	$hasToggles = !empty($obj['music-lyrics-obj']);
	$hasAnyKaraoke = false;
	foreach ($tracks as $t) {
		if (!empty($t['karaokeAudioFile'])) { $hasAnyKaraoke = true; break; }
	}
	$hasKaraokeApi = !empty($obj['music-karaoke-api']);
	$hasToggles = $hasToggles || $hasAnyKaraoke || ($hasAnyKaraoke && $hasKaraokeApi);

	if ($hasToggles) {
		$togglesDiv = elem('div');
		elem_add_class($togglesDiv, 'music-toggles');

		if (!empty($obj['music-lyrics-obj'])) {
			$lyricsToggle = elem('button');
			elem_add_class($lyricsToggle, 'music-lyrics-toggle');
			elem_append($lyricsToggle, 'Lyrics');
			elem_append($togglesDiv, $lyricsToggle);
		}

		if ($hasAnyKaraoke) {
			$instrToggle = elem('button');
			elem_add_class($instrToggle, 'music-instrumental-toggle');
			elem_append($instrToggle, 'Instrumental');
			elem_append($togglesDiv, $instrToggle);
		}

		if ($hasAnyKaraoke && $hasKaraokeApi) {
			$singToggle = elem('div');
			elem_add_class($singToggle, 'music-singalong-toggle');
			elem_attr($singToggle, 'title', 'Karaoke?');
			elem_append($singToggle, 'Karaoke?');
			elem_append($togglesDiv, $singToggle);
		}

		elem_append($elem, $togglesDiv);
	}

	return true;
}


/**
 *	implements save_state
 */
function music_save_state($args)
{
	$elem = &$args['elem'];
	$obj = &$args['obj'];

	if (!elem_has_class($elem, 'music-player') && !elem_has_class($elem, 'music-lyrics-screen')) {
		return false;
	}

	if (elem_has_class($elem, 'music-lyrics-screen')) {
		$obj['type'] = 'music-lyrics';
	} else {
		$obj['type'] = 'music';
	}
	$obj['module'] = 'music';

	// Save current track index
	$current = elem_attr($elem, 'data-music-current');
	if ($current !== NULL) {
		$obj['music-current'] = intval($current);
	}

	// Extract CSS dimensions/position via alter_save hooks
	invoke_hook('alter_save', array('obj'=>&$obj, 'elem'=>$elem));

	load_modules('glue');
	$ret = save_object($obj);
	if ($ret['#error']) {
		log_msg('error', 'music_save_state: error saving object');
		return false;
	} else {
		return true;
	}
}


/**
 *	implements render_page_early
 *
 *	Adds CSS and JS for music player
 */
function music_render_page_early($args)
{
	// Always load CSS
	html_add_css(base_url() . 'modules/music/music.css');

	if ($args['edit']) {
		// Edit mode JS
		html_add_js(base_url() . 'modules/music/music-edit.js');
	} else {
		// View mode JS — full playback engine with Media Session API
		html_add_js_inline('
			document.addEventListener("DOMContentLoaded", function() {
				var players = document.querySelectorAll(".music-player");
				for (var i = 0; i < players.length; i++) {
					(function(player) {
						var tracks = [];
						try { tracks = JSON.parse(player.getAttribute("data-music-tracks") || "[]"); } catch(e) {}
						if (tracks.length === 0) return;

						var currentTrack = parseInt(player.getAttribute("data-music-current")) || 0;
						if (currentTrack < 0 || currentTrack >= tracks.length) currentTrack = 0;

						var audio = player.querySelector(".music-audio");
						var coverImg = player.querySelector(".music-cover-img");
						var titleEl = player.querySelector(".music-title");
						var artistEl = player.querySelector(".music-artist");
						var playIcon = player.querySelector(".music-icon-play");
						var pauseIcon = player.querySelector(".music-icon-pause");
						var prevBtn = player.querySelector(".music-prev");
						var playPauseBtn = player.querySelector(".music-play-pause");
						var nextBtn = player.querySelector(".music-next");
						var progressBar = player.querySelector(".music-progress-bar");
						var progressFill = player.querySelector(".music-progress-fill");
						var timeElapsed = player.querySelector(".music-time-elapsed");
						var timeRemaining = player.querySelector(".music-time-remaining");

						// Lyrics screen
						var lyricsScreenId = player.getAttribute("data-music-lyrics-obj");
						var lyricsScreen = lyricsScreenId ? document.getElementById(lyricsScreenId) : null;
						var lyricsInner = lyricsScreen ? lyricsScreen.querySelector(".music-lyrics-inner") : null;
						var currentLRC = [];

						var lastLyricIndex = -1;
						var lyricsEnabled = false;
						var lyricsToggle = player.querySelector(".music-lyrics-toggle");

						var instrumentalEnabled = false;
						var instrumentalToggle = player.querySelector(".music-instrumental-toggle");
						// Lyrics screen starts hidden; user toggles via lyrics button
						if (lyricsScreen) lyricsScreen.style.display = "none";

						function formatTime(s) {
							if (!s || !isFinite(s)) return "0:00";
							var m = Math.floor(s / 60);
							var sec = Math.floor(s % 60);
							return m + ":" + (sec < 10 ? "0" : "") + sec;
						}

						function parseLRC(text) {
							if (!text) return [];
							var lines = [];
							var raw = text.split("\n");
							for (var i = 0; i < raw.length; i++) {
								var m = raw[i].match(/^\[(\d+):(\d+)[\.:](\d+)\]\s*(.*)/);
								if (m) {
									var t = parseInt(m[1]) * 60 + parseInt(m[2]) + parseInt(m[3]) / (m[3].length === 3 ? 1000 : 100);
									var txt = m[4].trim();
									if (txt) lines.push({ time: t, text: txt });
								}
							}
							return lines;
						}

						function getAudioUrl() {
							var track = tracks[currentTrack];
							return (instrumentalEnabled && track.karaokeAudioUrl) ? track.karaokeAudioUrl : (track.audioUrl || "");
						}

						function renderLyrics() {
							var track = tracks[currentTrack];
							var hasLrc = !!(track.hasLrc);
							var hasVideo = !!(track.lyricsVideoUrl);
							var hasLyrics = hasLrc || hasVideo;
							var hasKaraoke = !!(track.karaokeAudioUrl);
							// Show/hide toggle buttons based on current track
							if (lyricsToggle) {
								lyricsToggle.style.display = hasLyrics ? "" : "none";
							}
							if (instrumentalToggle) {
								instrumentalToggle.style.display = hasKaraoke ? "" : "none";
								if (!hasKaraoke && instrumentalEnabled) {
									instrumentalEnabled = false;
									instrumentalToggle.className = "music-instrumental-toggle";
								}
							}
							if (!lyricsInner) return;

							// Clean up any previous lyric video
							var oldVideo = lyricsInner.querySelector(".music-lyrics-video");
							if (oldVideo) {
								oldVideo.pause();
								oldVideo.removeAttribute("src");
								oldVideo.parentNode.removeChild(oldVideo);
							}

							// Lyric video takes priority over LRC text
							if (hasVideo) {
								currentLRC = [];
								lastLyricIndex = -1;
								lyricsInner.innerHTML = "";
								var vid = document.createElement("video");
								vid.className = "music-lyrics-video";
								vid.src = track.lyricsVideoUrl;
								vid.muted = true;
								vid.playsInline = true;
								vid.setAttribute("playsinline", "");
								vid.style.width = "100%";
								vid.style.height = "100%";
								vid.style.objectFit = "contain";
								lyricsInner.style.padding = "0";
								lyricsInner.appendChild(vid);
								// Sync video to audio position
								syncLyricsVideo();
								return;
							}

							lyricsInner.style.padding = "";
							// If LRC already cached, render immediately
							if (track._lrcText !== undefined) {
								applyLRC(track._lrcText);
								return;
							}
							// Fetch LRC from URL
							if (track.lrcUrl) {
								lyricsInner.innerHTML = "<div class=\"music-lrc-empty\">Loading...</div>";
								var xhr = new XMLHttpRequest();
								xhr.open("GET", track.lrcUrl, true);
								xhr.onload = function() {
									track._lrcText = xhr.status === 200 ? xhr.responseText : "";
									// Only apply if still on same track
									if (tracks[currentTrack] === track) applyLRC(track._lrcText);
								};
								xhr.onerror = function() {
									track._lrcText = "";
									if (tracks[currentTrack] === track) applyLRC("");
								};
								xhr.send();
							} else {
								applyLRC("");
							}
						}

						function applyLRC(text) {
							currentLRC = parseLRC(text);
							lastLyricIndex = -1;
							if (currentLRC.length === 0) {
								lyricsInner.innerHTML = "<div class=\"music-lrc-empty\">No lyrics</div>";
								return;
							}
							var h = "";
							for (var i = 0; i < currentLRC.length; i++) {
								h += "<div class=\"music-lrc-line\">" + currentLRC[i].text + "</div>";
							}
							lyricsInner.innerHTML = h;
						}

						function syncLyricsVideo() {
							if (!lyricsInner) return;
							var vid = lyricsInner.querySelector(".music-lyrics-video");
							if (!vid) return;
							// Match video position to audio
							vid.currentTime = audio.currentTime;
							if (!audio.paused) {
								var p = vid.play();
								if (p) p.catch(function() {});
							}
						}

						function updatePlayer() {
							var track = tracks[currentTrack];
							titleEl.textContent = track.title;
							artistEl.textContent = track.artist;
							if (coverImg) coverImg.src = track.coverUrl || "";
							audio.pause();
							audio.currentTime = 0;
							var url = getAudioUrl();
							var srcMp4 = player.querySelector(".music-source-mp4");
							var srcMpeg = player.querySelector(".music-source-mpeg");
							if (srcMp4) srcMp4.setAttribute("src", url);
							if (srcMpeg) srcMpeg.setAttribute("src", url);
							audio.load();
							playIcon.style.display = "";
							pauseIcon.style.display = "none";
							progressFill.style.width = "0%";
							timeElapsed.textContent = "0:00";
							timeRemaining.textContent = "-0:00";

							if ("mediaSession" in navigator) {
								navigator.mediaSession.metadata = new MediaMetadata({
									title: track.title,
									artist: track.artist,
									artwork: (function() {
									if (!track.coverUrl) return [];
									var u = track.coverUrl.indexOf("://") === -1 ? window.location.origin + track.coverUrl : track.coverUrl;
									return [
										{ src: u, sizes: "96x96" },
										{ src: u, sizes: "256x256" },
										{ src: u, sizes: "512x512" }
									];
								})()
								});
							}
							renderLyrics();
						}

						function togglePlay() {
							if (audio.paused) {
								var p = audio.play();
								if (p) p.catch(function(e) { console.log("Play failed:", e); });
							} else {
								audio.pause();
							}
						}

						function tryPlayAfterSwitch() {
							var p = audio.play();
							if (p) {
								p.catch(function() {
									playIcon.style.display = "";
									pauseIcon.style.display = "none";
								});
							}
						}

						function nextTrack() {
							var wasPlaying = !audio.paused;
							currentTrack = (currentTrack + 1) % tracks.length;
							updatePlayer();
							if (wasPlaying) {
								if (audio.readyState >= 3) {
									tryPlayAfterSwitch();
								} else {
									var handler = function() { tryPlayAfterSwitch(); };
									audio.addEventListener("canplay", handler, { once: true });
									setTimeout(function() {
										audio.removeEventListener("canplay", handler);
										tryPlayAfterSwitch();
									}, 3000);
								}
							}
						}

						function previousTrack() {
							var wasPlaying = !audio.paused;
							currentTrack = (currentTrack - 1 + tracks.length) % tracks.length;
							updatePlayer();
							if (wasPlaying) {
								if (audio.readyState >= 3) {
									tryPlayAfterSwitch();
								} else {
									var handler = function() { tryPlayAfterSwitch(); };
									audio.addEventListener("canplay", handler, { once: true });
									setTimeout(function() {
										audio.removeEventListener("canplay", handler);
										tryPlayAfterSwitch();
									}, 3000);
								}
							}
						}

						// Auto-advance on track end
						audio.addEventListener("ended", function() {
							currentTrack = (currentTrack + 1) % tracks.length;
							updatePlayer();
							if (audio.readyState >= 3) {
								tryPlayAfterSwitch();
							} else {
								var handler = function() { tryPlayAfterSwitch(); };
								audio.addEventListener("canplay", handler, { once: true });
								setTimeout(function() {
									audio.removeEventListener("canplay", handler);
									tryPlayAfterSwitch();
								}, 3000);
							}
						});

						// Sync play/pause icon + Media Session playback state + lyric video
						audio.addEventListener("play", function() {
							playIcon.style.display = "none";
							pauseIcon.style.display = "";
							if ("mediaSession" in navigator) {
								navigator.mediaSession.playbackState = "playing";
							}
							syncLyricsVideo();
						});

						audio.addEventListener("pause", function() {
							playIcon.style.display = "";
							pauseIcon.style.display = "none";
							if ("mediaSession" in navigator) {
								navigator.mediaSession.playbackState = "paused";
							}
							var vid = lyricsInner ? lyricsInner.querySelector(".music-lyrics-video") : null;
							if (vid) vid.pause();
						});

						audio.addEventListener("seeked", function() {
							syncLyricsVideo();
						});

						// Media Session position state for iOS progress bar
						audio.addEventListener("loadedmetadata", function() {
							if ("mediaSession" in navigator && audio.duration && isFinite(audio.duration)) {
								navigator.mediaSession.setPositionState({
									duration: audio.duration,
									playbackRate: audio.playbackRate,
									position: audio.currentTime
								});
							}
						});

						audio.addEventListener("timeupdate", function() {
							if (audio.duration && isFinite(audio.duration)) {
								var pct = (audio.currentTime / audio.duration) * 100;
								progressFill.style.width = pct + "%";
								timeElapsed.textContent = formatTime(audio.currentTime);
								timeRemaining.textContent = "-" + formatTime(audio.duration - audio.currentTime);
								if ("mediaSession" in navigator) {
									navigator.mediaSession.setPositionState({
										duration: audio.duration,
										playbackRate: audio.playbackRate,
										position: audio.currentTime
									});
								}
							}
							// Sync lyrics
							if (lyricsInner && currentLRC.length > 0) {
								var ct = audio.currentTime + (tracks[currentTrack].lrcOffset || 0);
								var idx = -1;
								for (var l = currentLRC.length - 1; l >= 0; l--) {
									if (currentLRC[l].time <= ct) { idx = l; break; }
								}
								if (idx !== lastLyricIndex) {
									lastLyricIndex = idx;
									var lineEls = lyricsInner.querySelectorAll(".music-lrc-line");
									for (var l = 0; l < lineEls.length; l++) {
										if (l === idx) {
											lineEls[l].classList.add("music-lrc-active");
											var targetTop = lineEls[l].offsetTop - (lyricsInner.clientHeight / 2) + (lineEls[l].offsetHeight / 2);
											lyricsInner.scrollTop = Math.max(0, targetTop);
										} else {
											lineEls[l].classList.remove("music-lrc-active");
										}
									}
								}
							}
						});

						// Seek on progress bar click/drag
						function seekFromEvent(e) {
							var rect = progressBar.getBoundingClientRect();
							var pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
							if (audio.duration && isFinite(audio.duration)) {
								audio.currentTime = pct * audio.duration;
							}
						}
						var isSeeking = false;
						progressBar.addEventListener("mousedown", function(e) {
							e.preventDefault(); e.stopPropagation();
							isSeeking = true; seekFromEvent(e);
						});
						document.addEventListener("mousemove", function(e) {
							if (isSeeking) { e.preventDefault(); seekFromEvent(e); }
						});
						document.addEventListener("mouseup", function() { isSeeking = false; });
						progressBar.addEventListener("touchstart", function(e) {
							e.stopPropagation();
							isSeeking = true; seekFromEvent(e.touches[0]);
						}, { passive: true });
						document.addEventListener("touchmove", function(e) {
							if (isSeeking) seekFromEvent(e.touches[0]);
						}, { passive: true });
						document.addEventListener("touchend", function() { isSeeking = false; });

						// Media Session action handlers
						if ("mediaSession" in navigator) {
							navigator.mediaSession.setActionHandler("play", togglePlay);
							navigator.mediaSession.setActionHandler("pause", togglePlay);
							navigator.mediaSession.setActionHandler("previoustrack", previousTrack);
							navigator.mediaSession.setActionHandler("nexttrack", nextTrack);
						}

						// Button click handlers
						prevBtn.addEventListener("click", function(e) { e.stopPropagation(); previousTrack(); });
						playPauseBtn.addEventListener("click", function(e) { e.stopPropagation(); togglePlay(); });
						nextBtn.addEventListener("click", function(e) { e.stopPropagation(); nextTrack(); });

						// Page navigation buttons
						var prevPageBtn = player.querySelector(".music-prev-page");
						var nextPageBtn = player.querySelector(".music-next-page");
						if (prevPageBtn) {
							prevPageBtn.addEventListener("click", function(e) {
								e.stopPropagation();
								window.location.href = this.getAttribute("data-page");
							});
						}
						if (nextPageBtn) {
							nextPageBtn.addEventListener("click", function(e) {
								e.stopPropagation();
								window.location.href = this.getAttribute("data-page");
							});
						}

						// Lyrics toggle
						if (lyricsToggle) {
							lyricsToggle.addEventListener("click", function(e) {
								e.stopPropagation();
								lyricsEnabled = !lyricsEnabled;
								this.classList.toggle("music-lyrics-on", lyricsEnabled);
								if (lyricsScreen) {
									lyricsScreen.style.display = lyricsEnabled ? "" : "none";
									if (lyricsEnabled) syncLyricsVideo();
								}
							});
						}

						// Instrumental toggle — swap audio source, preserve position
						if (instrumentalToggle) {
							instrumentalToggle.addEventListener("click", function(e) {
								e.stopPropagation();
								instrumentalEnabled = !instrumentalEnabled;
								this.classList.toggle("music-instrumental-on", instrumentalEnabled);
								var wasPlaying = !audio.paused;
								var pos = audio.currentTime;
								var url = getAudioUrl();
								var srcMp4 = player.querySelector(".music-source-mp4");
								var srcMpeg = player.querySelector(".music-source-mpeg");
								if (srcMp4) srcMp4.setAttribute("src", url);
								if (srcMpeg) srcMpeg.setAttribute("src", url);
								audio.load();
								var resume = function() {
									audio.currentTime = pos;
									if (wasPlaying) {
										var p = audio.play();
										if (p) p.catch(function() {});
									}
								};
								if (audio.readyState >= 3) {
									resume();
								} else {
									audio.addEventListener("canplay", resume, { once: true });
								}
							});
						}

						// Set initial Media Session metadata
						if ("mediaSession" in navigator && tracks[currentTrack]) {
							var initTrack = tracks[currentTrack];
							var initUrl = initTrack.coverUrl ? (initTrack.coverUrl.indexOf("://") === -1 ? window.location.origin + initTrack.coverUrl : initTrack.coverUrl) : "";
							navigator.mediaSession.metadata = new MediaMetadata({
								title: initTrack.title,
								artist: initTrack.artist,
								artwork: initUrl ? [
									{ src: initUrl, sizes: "96x96" },
									{ src: initUrl, sizes: "256x256" },
									{ src: initUrl, sizes: "512x512" }
								] : []
							});
						}

						// Initial lyrics render
						renderLyrics();

						// ============================================
						// Live Sing-Along (WebRTC)
						// ============================================
						var apiBase = player.getAttribute("data-karaoke-api");
						if (apiBase) {
						(function() {
							var playerId = player.getAttribute("id") || "player";
							var page = window.location.pathname.replace(/^\//, "").replace(/\/$/, "") || "index";
							var singBtn = player.querySelector(".music-singalong-toggle");
							var rtcConfig = { iceServers: [
								{ urls: "stun:stun.l.google.com:19302" },
								{ urls: "stun:stun1.l.google.com:19302" }
							]};

							// Cached username
							function getUsername() {
								var name = null;
								try { name = localStorage.getItem("karaoke-username"); } catch(e) {}
								if (!name) {
									name = prompt("Your name?");
									if (name) { try { localStorage.setItem("karaoke-username", name); } catch(e) {} }
								}
								return name;
							}

							// --- Singer state ---
							var singerRoom = null;

							// --- Listener state ---
							var listenerState = null;
							var liveBanner = null;
							var listeningDiv = null;

							function apiCall(method, path, body) {
								var opts = { method: method, headers: { "Content-Type": "application/json" } };
								if (body) opts.body = JSON.stringify(body);
								return fetch(apiBase + path, opts).then(function(r) { return r.json(); });
							}

							// ----- KARAOKE BUTTON -----
							if (singBtn) {
								singBtn.addEventListener("click", function(e) {
									e.stopPropagation();
									if (singerRoom) {
										stopSinging();
										return;
									}
									if (listenerState) {
										stopListening();
										return;
									}
									// Check for active rooms, always show a banner
									singBtn.textContent = "checking...";
									apiCall("GET", "/rooms?page=" + encodeURIComponent(page)).then(function(rooms) {
										var room = null;
										for (var r = 0; r < rooms.length; r++) {
											if (rooms[r].player_id === playerId) { room = rooms[r]; break; }
										}
										singBtn.textContent = "Karaoke?";
										if (room) {
											showLiveBanner(room);
										} else {
											showStartBanner();
										}
									}).catch(function() {
										singBtn.textContent = "Karaoke?";
									});
								});
							}

							// ----- SINGER -----
							function startSinging() {
								var name = getUsername();
								if (!name) { if (singBtn) singBtn.textContent = "Karaoke?"; return; }
								removeLiveBanner();
								if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
									alert("Mic access requires HTTPS");
									if (singBtn) singBtn.textContent = "Karaoke?";
									return;
								}
								navigator.mediaDevices.getUserMedia({
									audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false },
									video: false
								}).then(function(stream) {
									// Mix mic + backing track into one stream for WebRTC
									var audioCtx = new (window.AudioContext || window.webkitAudioContext)();
									if (audioCtx.state === "suspended") audioCtx.resume();
									// Compressor auto-balances voice vs backing track
									var compressor = audioCtx.createDynamicsCompressor();
									compressor.threshold.value = -30;
									compressor.knee.value = 12;
									compressor.ratio.value = 8;
									compressor.attack.value = 0.003;
									compressor.release.value = 0.15;
									var mixedDest = audioCtx.createMediaStreamDestination();
									compressor.connect(mixedDest);
									
									// Mic: boost and add to mix
									var micSource = audioCtx.createMediaStreamSource(stream);
									var micGain = audioCtx.createGain();
									micGain.gain.value = 6.0;
									micSource.connect(micGain);
									micGain.connect(compressor);
									
									// Backing track: capture and add to mix (reduced volume)
									var trackMixGain = null;
									try {
										var trackSource = audioCtx.createMediaElementSource(audio);
										// Full volume to singer speakers
										trackSource.connect(audioCtx.destination);
										// Reduced volume into the mix so voice cuts through
										trackMixGain = audioCtx.createGain();
										trackMixGain.gain.value = 0.2;
										trackSource.connect(trackMixGain);
										trackMixGain.connect(compressor);
									} catch(e) {
										console.warn("Could not capture backing track:", e);
									}
									var mixedStream = mixedDest.stream;

									// Start playback if paused
									if (audio.paused) {
										var p = audio.play();
										if (p) p.catch(function() {});
									}
									apiCall("POST", "/room", {
										page: page, player_id: playerId, singer_name: name,
										track_index: currentTrack, track_title: tracks[currentTrack].title,
										current_time: audio.currentTime
									}).then(function(res) {
										singerRoom = {
											roomId: res.room_id, micStream: stream, mixedStream: mixedStream, trackMixGain: trackMixGain,
											audioCtx: audioCtx, peers: {}, pollTs: 0
										};
										if (singBtn) {
											singBtn.classList.add("music-singing");
											singBtn.innerHTML = "Stop Singing <span class=\"music-mic-indicator\"></span>";
										// Mix slider for singer to adjust track vs voice balance
										var mixSlider = document.createElement("div");
										mixSlider.className = "music-mix-slider";
										mixSlider.innerHTML = "<label>Track <input type=\\"range\\" min=\\"0\\" max=\\"100\\" value=\\"60\\" step=\\"1\\"> Voice</label>";
										var rangeInput = mixSlider.querySelector("input");
										rangeInput.addEventListener("input", function() {
											var val = (100 - parseInt(this.value)) / 100 * 0.5;
											if (singerRoom && singerRoom.trackMixGain) singerRoom.trackMixGain.gain.value = val;
										});
										player.appendChild(mixSlider);
										singerRoom._mixSlider = mixSlider;
										}
										// Heartbeat every 5s for fresher timestamps
										singerRoom.heartbeatInterval = setInterval(function() {
											if (!singerRoom) return;
											apiCall("POST", "/room/" + singerRoom.roomId + "/heartbeat", {
												track_index: currentTrack,
												track_title: tracks[currentTrack].title,
												current_time: audio.currentTime
											});
										}, 5000);
										// Poll signals (3s — singer stays alive for new listeners)
										singerRoom.pollInterval = setInterval(function() {
											singerPollSignals();
										}, 3000);
									});
								}).catch(function(err) {
									alert("Mic access denied: " + err.message);
									if (singBtn) singBtn.textContent = "Karaoke?";
								});
							}

							function singerPollSignals() {
								if (!singerRoom) return;
								apiCall("GET", "/room/" + singerRoom.roomId + "/signal?peer_id=singer&since=" + singerRoom.pollTs).then(function(signals) {
									for (var s = 0; s < signals.length; s++) {
										var sig = signals[s];
										if (sig.ts > singerRoom.pollTs) singerRoom.pollTs = sig.ts;
										if (sig.type === "offer-request") {
											singerCreateOffer(sig.from);
										} else if (sig.type === "answer" && singerRoom.peers[sig.from]) {
											var pc = singerRoom.peers[sig.from];
											pc.setRemoteDescription(new RTCSessionDescription(sig.data)).then(function() {
												// Drain queued ICE candidates
												var q = pc._iceQueue || [];
												pc._iceQueue = [];
												for (var i = 0; i < q.length; i++) {
													pc.addIceCandidate(new RTCIceCandidate(q[i]));
												}
											});
										} else if (sig.type === "ice" && singerRoom.peers[sig.from]) {
											var pc = singerRoom.peers[sig.from];
											if (pc.remoteDescription) {
												pc.addIceCandidate(new RTCIceCandidate(sig.data));
											} else {
												if (!pc._iceQueue) pc._iceQueue = [];
												pc._iceQueue.push(sig.data);
											}
										}
									}
								});
							}

							function singerCreateOffer(listenerId) {
								if (!singerRoom) return;
								var pc = new RTCPeerConnection(rtcConfig);
								singerRoom.peers[listenerId] = pc;
								var iceQueue = [];
								singerRoom.mixedStream.getTracks().forEach(function(t) { pc.addTrack(t, singerRoom.mixedStream); });

								// DataChannel for time sync
								var dc = pc.createDataChannel("sync");
								pc._dc = dc;
								dc.onopen = function() {
									pc._syncInterval = setInterval(function() {
										if (dc.readyState === "open") {
											dc.send(JSON.stringify({ t: audio.currentTime, ti: currentTrack, p: !audio.paused, d: audio.duration || 0 }));
										}
									}, 2000);
								};
								dc.onclose = function() {
									if (pc._syncInterval) clearInterval(pc._syncInterval);
								};

								pc.onicecandidate = function(e) {
									if (e.candidate) {
										iceQueue.push(e.candidate.toJSON());
									} else {
										for (var i = 0; i < iceQueue.length; i++) {
											apiCall("POST", "/room/" + singerRoom.roomId + "/signal", {
												from: "singer", to: listenerId, type: "ice", data: iceQueue[i]
											});
										}
									}
								};
								pc.onconnectionstatechange = function() {
									if (pc.connectionState === "disconnected" || pc.connectionState === "failed") {
										if (pc._syncInterval) clearInterval(pc._syncInterval);
										pc.close();
										delete singerRoom.peers[listenerId];
									}
								};
								pc.createOffer().then(function(offer) {
									return pc.setLocalDescription(offer);
								}).then(function() {
									apiCall("POST", "/room/" + singerRoom.roomId + "/signal", {
										from: "singer", to: listenerId, type: "offer", data: pc.localDescription.toJSON()
									});
								});
							}

							function singerBroadcastSync() {
								if (!singerRoom) return;
								var msg = JSON.stringify({ t: audio.currentTime, ti: currentTrack, p: !audio.paused, d: audio.duration || 0 });
								Object.keys(singerRoom.peers).forEach(function(k) {
									var dc = singerRoom.peers[k]._dc;
									if (dc && dc.readyState === "open") dc.send(msg);
								});
							}

							// Broadcast immediately on play/pause when singing
							audio.addEventListener("play", function() { singerBroadcastSync(); });
							audio.addEventListener("pause", function() { singerBroadcastSync(); });

							function stopSinging() {
								if (!singerRoom) return;
								clearInterval(singerRoom.heartbeatInterval);
								clearInterval(singerRoom.pollInterval);
								Object.keys(singerRoom.peers).forEach(function(k) {
									if (singerRoom.peers[k]._syncInterval) clearInterval(singerRoom.peers[k]._syncInterval);
									singerRoom.peers[k].close();
								});
								if (singerRoom._mixSlider && singerRoom._mixSlider.parentNode) singerRoom._mixSlider.parentNode.removeChild(singerRoom._mixSlider);
								singerRoom.micStream.getTracks().forEach(function(t) { t.stop(); });
								if (singerRoom.audioCtx) singerRoom.audioCtx.close();
								apiCall("DELETE", "/room/" + singerRoom.roomId);
								// Reload audio so it plays through default output again
								var wasPlaying = !audio.paused;
								var pos = audio.currentTime;
								audio.load();
								audio.addEventListener("canplay", function() {
									audio.currentTime = pos;
									if (wasPlaying) audio.play().catch(function() {});
								}, { once: true });
								singerRoom = null;
								if (singBtn) {
									singBtn.classList.remove("music-singing");
									singBtn.textContent = "Karaoke?";
								}
							}

							// ----- LISTENER -----
							function showLiveBanner(room) {
								removeLiveBanner();
								liveBanner = document.createElement("div");
								liveBanner.className = "music-live-banner";
								liveBanner.innerHTML = "<span class=\"music-mic-indicator\"></span> " + room.singer_name + " is singing! Tap to listen";
								liveBanner.addEventListener("click", function(e) {
									e.stopPropagation();
									startListening(room);
								});
								player.appendChild(liveBanner);
							}

							function showStartBanner() {
								removeLiveBanner();
								liveBanner = document.createElement("div");
								liveBanner.className = "music-live-banner";
								liveBanner.textContent = "No one has the mic. Start singing?";
								liveBanner.addEventListener("click", function(e) {
									e.stopPropagation();
									startSinging();
								});
								player.appendChild(liveBanner);
							}

							function removeLiveBanner() {
								if (liveBanner && liveBanner.parentNode) liveBanner.parentNode.removeChild(liveBanner);
								liveBanner = null;
							}

							function startListening(room) {
								removeLiveBanner();
								var peerId = "listener-" + Math.random().toString(36).substr(2, 8);
								listenerState = { roomId: room.room_id, peerId: peerId, pc: null, remoteAudio: null, pollTs: 0, iceQueue: [], prevVolume: audio.volume };

								// Mute local audio - listener hears mixed stream via WebRTC
								audio.pause();
								audio.volume = 0;

								// Sync track display to singer (UI only, no playback)
								if (room.track_index !== undefined && room.track_index !== currentTrack) {
									currentTrack = room.track_index;
									var track = tracks[currentTrack];
									titleEl.textContent = track.title;
									artistEl.textContent = track.artist;
									if (coverImg) coverImg.src = track.coverUrl || "";
									renderLyrics();
								}

								// Send offer request
								apiCall("POST", "/room/" + room.room_id + "/signal", {
									from: peerId, to: "singer", type: "offer-request", data: {}
								});

								// Poll for signals (only during handshake, stopped on connect)
								listenerState.pollInterval = setInterval(function() {
									listenerPollSignals();
								}, 1500);

								showListeningUI(room.singer_name);

								// UI update loop: interpolate singer time for progress bar + lyrics
								(function listenerUILoop() {
									if (!listenerState) return;
									var st = listenerState._syncTime;
									if (st !== undefined) {
										var elapsed = listenerState._playing ? (Date.now() - listenerState._syncTs) / 1000 : 0;
										var ct = st + elapsed;
										var dur = listenerState._syncDuration || 0;
										if (dur > 0) {
											var pct = (ct / dur) * 100;
											progressFill.style.width = Math.min(pct, 100) + "%";
											timeElapsed.textContent = formatTime(ct);
											timeRemaining.textContent = "-" + formatTime(Math.max(0, dur - ct));
										}
										// Sync lyrics
										if (lyricsInner && currentLRC.length > 0) {
											var lt = ct + (tracks[currentTrack].lrcOffset || 0);
											var idx = -1;
											for (var l = currentLRC.length - 1; l >= 0; l--) {
												if (currentLRC[l].time <= lt) { idx = l; break; }
											}
											if (idx !== lastLyricIndex) {
												lastLyricIndex = idx;
												var lineEls = lyricsInner.querySelectorAll(".music-lrc-line");
												for (var l = 0; l < lineEls.length; l++) {
													if (l === idx) {
														lineEls[l].classList.add("music-lrc-active");
														var targetTop = lineEls[l].offsetTop - (lyricsInner.clientHeight / 2) + (lineEls[l].offsetHeight / 2);
														lyricsInner.scrollTop = Math.max(0, targetTop);
													} else {
														lineEls[l].classList.remove("music-lrc-active");
													}
												}
											}
										}
									}
									requestAnimationFrame(listenerUILoop);
								})();
							}

							function listenerPollSignals() {
								if (!listenerState) return;
								apiCall("GET", "/room/" + listenerState.roomId + "/signal?peer_id=" + listenerState.peerId + "&since=" + listenerState.pollTs).then(function(signals) {
									for (var s = 0; s < signals.length; s++) {
										var sig = signals[s];
										if (sig.ts > listenerState.pollTs) listenerState.pollTs = sig.ts;
										if (sig.type === "offer") {
											listenerHandleOffer(sig.data);
										} else if (sig.type === "ice") {
											if (listenerState.pc && listenerState.pc.remoteDescription) {
												listenerState.pc.addIceCandidate(new RTCIceCandidate(sig.data));
											} else {
												listenerState.iceQueue.push(sig.data);
											}
										}
									}
								}).catch(function() {
									stopListening(true);
								});
							}

							function listenerHandleOffer(offerData) {
								if (!listenerState) return;
								var pc = new RTCPeerConnection(rtcConfig);
								listenerState.pc = pc;
								var localIceQueue = [];
								pc.onicecandidate = function(e) {
									if (e.candidate) {
										localIceQueue.push(e.candidate.toJSON());
									} else {
										for (var i = 0; i < localIceQueue.length; i++) {
											apiCall("POST", "/room/" + listenerState.roomId + "/signal", {
												from: listenerState.peerId, to: "singer", type: "ice", data: localIceQueue[i]
											});
										}
									}
								};
								pc.ontrack = function(e) {
									var remoteAudio = new Audio();
									remoteAudio.srcObject = e.streams[0];
									remoteAudio.play().catch(function() {});
									listenerState.remoteAudio = remoteAudio;
								};
								pc.ondatachannel = function(e) {
									var dc = e.channel;
									dc.onmessage = function(evt) {
										try {
											var msg = JSON.parse(evt.data);
											// Sync track display if singer switched
											if (msg.ti !== undefined && msg.ti !== currentTrack) {
												currentTrack = msg.ti;
												var track = tracks[currentTrack];
												titleEl.textContent = track.title;
												artistEl.textContent = track.artist;
												if (coverImg) coverImg.src = track.coverUrl || "";
												renderLyrics();
											}
											// Store sync time for progress bar and lyrics
											if (msg.t !== undefined && listenerState) {
												listenerState._syncTime = msg.t;
												listenerState._syncTs = Date.now();
												listenerState._playing = msg.p;
												if (msg.d) listenerState._syncDuration = msg.d;
											}
										} catch(err) {}
									};
								};
								pc.onconnectionstatechange = function() {
									if (pc.connectionState === "connected") {
										// Handshake done — stop polling signals
										if (listenerState && listenerState.pollInterval) {
											clearInterval(listenerState.pollInterval);
											listenerState.pollInterval = null;
										}
									} else if (pc.connectionState === "disconnected" || pc.connectionState === "failed") {
										stopListening(true);
									}
								};
								pc.setRemoteDescription(new RTCSessionDescription(offerData)).then(function() {
									// Drain any ICE candidates that arrived before remote description was set
									var queued = listenerState.iceQueue;
									listenerState.iceQueue = [];
									for (var i = 0; i < queued.length; i++) {
										pc.addIceCandidate(new RTCIceCandidate(queued[i]));
									}
									return pc.createAnswer();
								}).then(function(answer) {
									return pc.setLocalDescription(answer);
								}).then(function() {
									apiCall("POST", "/room/" + listenerState.roomId + "/signal", {
										from: listenerState.peerId, to: "singer", type: "answer", data: pc.localDescription.toJSON()
									});
								});
							}

							function showListeningUI(singerName) {
								removeListeningUI();
								listeningDiv = document.createElement("div");
								listeningDiv.className = "music-live-listening";
								listeningDiv.innerHTML = "<span class=\"music-mic-indicator\"></span> Listening to " + singerName +
									" <button class=\"music-stop-listening\">Stop</button>";
								listeningDiv.querySelector(".music-stop-listening").addEventListener("click", function(e) {
									e.stopPropagation();
									stopListening();
								});
								player.appendChild(listeningDiv);
								if (singBtn) {
									singBtn.classList.add("music-singing");
									singBtn.textContent = "Listening...";
								}
							}

							function removeListeningUI() {
								if (listeningDiv && listeningDiv.parentNode) listeningDiv.parentNode.removeChild(listeningDiv);
								listeningDiv = null;
							}

							function stopListening(pauseTrack) {
								if (!listenerState) return;
								// Restore backing track volume
								audio.volume = listenerState.prevVolume || 1.0;
								clearInterval(listenerState.pollInterval);
								if (listenerState.pc) listenerState.pc.close();
								if (listenerState.remoteAudio) {
									listenerState.remoteAudio.pause();
									listenerState.remoteAudio.srcObject = null;
								}
								listenerState = null;
								removeListeningUI();
								if (singBtn) {
									singBtn.classList.remove("music-singing");
									singBtn.textContent = "Karaoke?";
								}
								if (pauseTrack) audio.pause();
							}

							// Cleanup on page unload
							window.addEventListener("beforeunload", function() {
								if (singerRoom) stopSinging();
								if (listenerState) stopListening();
							});
						})();
						}

					})(players[i]);
				}
			});
		', 5, 'music');
	}
}


// ============================================================================
// Backend services
// ============================================================================

/**
 *	Create a new music player
 */
function music_create($args)
{
	load_modules('glue');

	if (empty($args['page'])) {
		return response('Required argument "page" is missing', 400);
	}

	// Create new object
	$obj = create_object(array('page' => $args['page']));
	if ($obj['#error']) {
		return $obj;
	}
	$obj = $obj['#data'];

	$obj['type'] = 'music';
	$obj['module'] = 'music';
	$obj['music-tracks'] = '[]';
	$obj['music-current'] = '0';
	$obj['music-size'] = 'md';

	// Set position if provided
	if (!empty($args['x'])) {
		$obj['object-left'] = $args['x'];
	}
	if (!empty($args['y'])) {
		$obj['object-top'] = $args['y'];
	}

	// Default size (md)
	$obj['object-width'] = '400px';
	$obj['object-height'] = '220px';

	$ret = save_object($obj);
	if ($ret['#error']) {
		return $ret;
	}

	// Render and return HTML
	$ret = render_object(array('name' => $obj['name'], 'edit' => true));
	if ($ret['#error']) {
		return $ret;
	}

	return response($ret['#data']);
}

register_service('music.create', 'music_create', array('auth'=>true));


/**
 *	Get music player data
 */
function music_get_data($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}

	$obj = load_object(array('name' => $args['name']));
	if ($obj['#error']) {
		return response('Music player not found', 404);
	}
	$obj = $obj['#data'];

	$tracks = json_decode($obj['music-tracks'] ?? '[]', true);
	$current = intval($obj['music-current'] ?? 0);

	// Build full URLs for each track
	$a = expl('.', $obj['name']);
	$pagename = $a[0];

	if (CONTENT_DIR[0] === '/') {
		$content_relative = basename(CONTENT_DIR);
	} else {
		$content_relative = CONTENT_DIR;
	}
	$base = base_url() . $content_relative . '/' . $pagename . '/shared/';

	$shared_dir = CONTENT_DIR . '/' . $pagename . '/shared';
	foreach ($tracks as &$track) {
		if (!empty($track['audioFile'])) {
			$track['audioUrl'] = $base . rawurlencode($track['audioFile']);
		}
		if (!empty($track['coverFile'])) {
			$track['coverUrl'] = $base . rawurlencode($track['coverFile']);
		}
		// Read LRC from file if available
		if (!empty($track['lrcFile'])) {
			$lrcPath = $shared_dir . '/' . $track['lrcFile'];
			if (file_exists($lrcPath)) {
				$track['lrc'] = file_get_contents($lrcPath);
			}
		}
	}

	return response(array(
		'tracks' => $tracks,
		'current' => $current
	));
}

register_service('music.get_data', 'music_get_data', array('auth'=>true));


/**
 *	Update tracks array
 */
function music_update_tracks($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}

	if (!isset($args['tracks'])) {
		return response('Required argument "tracks" is missing', 400);
	}

	$tracks = json_decode($args['tracks'], true);
	if (!is_array($tracks)) {
		return response('Invalid tracks data', 400);
	}

	// Strip runtime-only fields that shouldn't be stored
	foreach ($tracks as &$t) {
		unset($t['lrc']);
		unset($t['audioUrl']);
		unset($t['coverUrl']);
		unset($t['karaokeAudioUrl']);
		unset($t['lyricsVideoUrl']);
	}

	$obj = load_object(array('name' => $args['name']));
	if ($obj['#error']) {
		return response('Music player not found', 404);
	}
	$obj = $obj['#data'];

	$obj['music-tracks'] = json_encode($tracks);

	return save_object($obj);
}

register_service('music.update_tracks', 'music_update_tracks', array('auth'=>true));


/**
 *	Upload audio file
 */
function music_upload_audio($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}

	if (empty($_FILES['file'])) {
		return response('No file uploaded', 400);
	}

	$file = $_FILES['file'];
	if ($file['error'] !== UPLOAD_ERR_OK) {
		return response('File upload error', 400);
	}

	// Get page name from object name
	$a = expl('.', $args['name']);
	$pagename = $a[0];

	$shared_dir = CONTENT_DIR . '/' . $pagename . '/shared';
	if (!is_dir($shared_dir)) {
		@mkdir($shared_dir, 0777, true);
	}

	$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
	if (!in_array($ext, array('mp3', 'mp4', 'm4a', 'ogg', 'wav', 'aac', 'webm', 'flac'))) {
		$ext = 'mp3';
	}

	$filename = time() . '_' . rand(1000, 9999) . '.' . $ext;
	$dest = $shared_dir . '/' . $filename;

	if (!move_uploaded_file($file['tmp_name'], $dest)) {
		return response('Failed to save uploaded file', 500);
	}

	return response(array('filename' => $filename));
}

register_service('music.upload_audio', 'music_upload_audio', array('auth'=>true));


/**
 *	Upload cover image
 */
function music_upload_cover($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}

	if (empty($_FILES['file'])) {
		return response('No file uploaded', 400);
	}

	$file = $_FILES['file'];
	if ($file['error'] !== UPLOAD_ERR_OK) {
		return response('File upload error', 400);
	}

	// Get page name from object name
	$a = expl('.', $args['name']);
	$pagename = $a[0];

	$shared_dir = CONTENT_DIR . '/' . $pagename . '/shared';
	if (!is_dir($shared_dir)) {
		@mkdir($shared_dir, 0777, true);
	}

	$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
	if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'))) {
		$ext = 'jpg';
	}

	$filename = time() . '_' . rand(1000, 9999) . '.' . $ext;
	$dest = $shared_dir . '/' . $filename;

	if (!move_uploaded_file($file['tmp_name'], $dest)) {
		return response('Failed to save uploaded file', 500);
	}

	return response(array('filename' => $filename));
}

register_service('music.upload_cover', 'music_upload_cover', array('auth'=>true));


/**
 *	Upload lyric video (mp4/webm)
 */
function music_upload_video($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}

	if (empty($_FILES['file'])) {
		return response('No file uploaded', 400);
	}

	$file = $_FILES['file'];
	if ($file['error'] !== UPLOAD_ERR_OK) {
		return response('File upload error', 400);
	}

	$a = expl('.', $args['name']);
	$pagename = $a[0];

	$shared_dir = CONTENT_DIR . '/' . $pagename . '/shared';
	if (!is_dir($shared_dir)) {
		@mkdir($shared_dir, 0777, true);
	}

	$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
	if (!in_array($ext, array('mp4', 'webm'))) {
		$ext = 'mp4';
	}

	$filename = time() . '_' . rand(1000, 9999) . '.' . $ext;
	$dest = $shared_dir . '/' . $filename;

	if (!move_uploaded_file($file['tmp_name'], $dest)) {
		return response('Failed to save uploaded file', 500);
	}

	return response(array('filename' => $filename));
}

register_service('music.upload_video', 'music_upload_video', array('auth'=>true));


/**
 *	Set player size preset and update dimensions
 */
function music_set_size($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}

	$size = $args['size'] ?? 'md';
	if (!in_array($size, array('sm', 'md', 'lg', 'vt'))) {
		$size = 'md';
	}

	$obj = load_object(array('name' => $args['name']));
	if ($obj['#error']) {
		return response('Music player not found', 404);
	}
	$obj = $obj['#data'];

	$obj['music-size'] = $size;

	// Set matching dimensions
	$sizes = array(
		'sm' => array('300px', '160px'),
		'md' => array('400px', '220px'),
		'lg' => array('520px', '300px'),
		'vt' => array('240px', '400px')
	);
	$obj['object-width'] = $sizes[$size][0];
	$obj['object-height'] = $sizes[$size][1];

	$ret = save_object($obj);
	if ($ret['#error']) {
		return $ret;
	}

	$ret = render_object(array('name' => $obj['name'], 'edit' => true));
	if ($ret['#error']) {
		return $ret;
	}

	return response($ret['#data']);
}

register_service('music.set_size', 'music_set_size', array('auth'=>true));


/**
 *	Save LRC lyrics to a file (keeps tracks JSON small)
 */
function music_save_lrc($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}
	if (!isset($args['track_index'])) {
		return response('Required argument "track_index" is missing', 400);
	}
	if (!isset($args['lrc'])) {
		return response('Required argument "lrc" is missing', 400);
	}

	$obj = load_object(array('name' => $args['name']));
	if ($obj['#error']) {
		return response('Music player not found', 404);
	}
	$obj = $obj['#data'];

	$tracks = json_decode($obj['music-tracks'] ?? '[]', true);
	if (!is_array($tracks)) {
		$tracks = array();
	}

	$idx = intval($args['track_index']);
	if ($idx < 0 || $idx >= count($tracks)) {
		return response('Invalid track index', 400);
	}

	// Get shared directory
	$a = expl('.', $args['name']);
	$pagename = $a[0];
	$shared_dir = CONTENT_DIR . '/' . $pagename . '/shared';
	if (!is_dir($shared_dir)) {
		@mkdir($shared_dir, 0777, true);
	}

	$lrc = $args['lrc'];

	if (empty(trim($lrc))) {
		// Clear: delete old file if exists
		if (!empty($tracks[$idx]['lrcFile'])) {
			@unlink($shared_dir . '/' . $tracks[$idx]['lrcFile']);
			unset($tracks[$idx]['lrcFile']);
		}
		// Also clear legacy inline lrc
		unset($tracks[$idx]['lrc']);
	} else {
		// Delete old file if replacing
		if (!empty($tracks[$idx]['lrcFile'])) {
			@unlink($shared_dir . '/' . $tracks[$idx]['lrcFile']);
		}

		$filename = time() . '_' . rand(1000, 9999) . '.lrc';
		$dest = $shared_dir . '/' . $filename;
		if (file_put_contents($dest, $lrc) === false) {
			return response('Failed to save LRC file', 500);
		}

		$tracks[$idx]['lrcFile'] = $filename;
		// Remove legacy inline lrc
		unset($tracks[$idx]['lrc']);
	}

	$obj['music-tracks'] = json_encode($tracks);
	$ret = save_object($obj);
	if ($ret['#error']) {
		return $ret;
	}

	return response(array('lrcFile' => $tracks[$idx]['lrcFile'] ?? ''));
}

register_service('music.save_lrc', 'music_save_lrc', array('auth'=>true));


/**
 *	Fetch synced lyrics from lrclib.net
 */
function music_fetch_lyrics($args)
{
	if (empty($args['track_name'])) {
		return response('Required argument "track_name" is missing', 400);
	}

	$params = array(
		'track_name' => $args['track_name']
	);
	if (!empty($args['artist_name'])) {
		$params['artist_name'] = $args['artist_name'];
	}

	$url = 'https://lrclib.net/api/search?' . http_build_query($params);

	$ctx = stream_context_create(array('http' => array(
		'header' => "User-Agent: hotglue2/1.0\r\n",
		'timeout' => 10
	)));

	$result = @file_get_contents($url, false, $ctx);
	if ($result === false) {
		return response('Failed to fetch lyrics from lrclib.net', 500);
	}

	$data = json_decode($result, true);
	if (!is_array($data)) {
		return response('Invalid response from lyrics API', 500);
	}

	// Filter to entries with synced lyrics
	$results = array();
	foreach ($data as $item) {
		if (!empty($item['syncedLyrics'])) {
			$results[] = array(
				'trackName' => $item['trackName'] ?? '',
				'artistName' => $item['artistName'] ?? '',
				'albumName' => $item['albumName'] ?? '',
				'duration' => $item['duration'] ?? 0,
				'syncedLyrics' => $item['syncedLyrics']
			);
		}
	}

	return response($results);
}

register_service('music.fetch_lyrics', 'music_fetch_lyrics', array('auth'=>true));


/**
 *	Create a lyrics screen object linked to a music player
 */
function music_create_lyrics($args)
{
	load_modules('glue');

	if (empty($args['page'])) {
		return response('Required argument "page" is missing', 400);
	}
	if (empty($args['parent'])) {
		return response('Required argument "parent" is missing', 400);
	}

	$obj = create_object(array('page' => $args['page']));
	if ($obj['#error']) {
		return $obj;
	}
	$obj = $obj['#data'];

	$obj['type'] = 'music-lyrics';
	$obj['module'] = 'music';
	$obj['music-parent'] = $args['parent'];

	if (!empty($args['x'])) {
		$obj['object-left'] = $args['x'];
	}
	if (!empty($args['y'])) {
		$obj['object-top'] = $args['y'];
	}
	$obj['object-width'] = '350px';
	$obj['object-height'] = '500px';

	$ret = save_object($obj);
	if ($ret['#error']) {
		return $ret;
	}

	// Link to the parent music player
	$parent = load_object(array('name' => $args['parent']));
	if (!$parent['#error']) {
		$parent = $parent['#data'];
		$parent['music-lyrics-obj'] = $obj['name'];
		save_object($parent);
	}

	$ret = render_object(array('name' => $obj['name'], 'edit' => true));
	if ($ret['#error']) {
		return $ret;
	}

	return response($ret['#data']);
}

register_service('music.create_lyrics', 'music_create_lyrics', array('auth'=>true));


/**
 *	Set lyrics screen font size
 */
function music_set_lyrics_size($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}

	$size = $args['size'] ?? 'md';
	if (!in_array($size, array('sm', 'md', 'lg', 'xl'))) {
		$size = 'md';
	}

	$obj = load_object(array('name' => $args['name']));
	if ($obj['#error']) {
		return response('Lyrics screen not found', 404);
	}
	$obj = $obj['#data'];

	$obj['music-lyrics-size'] = $size;

	$ret = save_object($obj);
	if ($ret['#error']) {
		return $ret;
	}

	$ret = render_object(array('name' => $obj['name'], 'edit' => true));
	if ($ret['#error']) {
		return $ret;
	}

	return response($ret['#data']);
}

register_service('music.set_lyrics_size', 'music_set_lyrics_size', array('auth'=>true));


/**
 *	Set lyrics font size in pixels
 */
function music_set_lyrics_fontsize($args)
{
	load_modules('glue');

	if (empty($args['name'])) {
		return response('Required argument "name" is missing', 400);
	}

	$fontsize = intval($args['fontsize'] ?? 20);
	if ($fontsize < 8) $fontsize = 8;
	if ($fontsize > 80) $fontsize = 80;

	$obj = load_object(array('name' => $args['name']));
	if ($obj['#error']) {
		return response('Lyrics screen not found', 404);
	}
	$obj = $obj['#data'];

	$obj['music-lyrics-fontsize'] = $fontsize;

	$ret = save_object($obj);
	if ($ret['#error']) {
		return $ret;
	}

	$ret = render_object(array('name' => $obj['name'], 'edit' => true));
	if ($ret['#error']) {
		return $ret;
	}

	return response($ret['#data']);
}

register_service('music.set_lyrics_fontsize', 'music_set_lyrics_fontsize', array('auth'=>true));


/**
 *	implements delete_object
 *
 *	Clean up lyrics screen when music player is deleted
 */
function music_delete_object($args)
{
	$obj = $args['obj'];
	if (!isset($obj['type'])) {
		return false;
	}

	if ($obj['type'] == 'music' && !empty($obj['music-lyrics-obj'])) {
		// Delete linked lyrics screen
		load_modules('glue');
		delete_object(array('name' => $obj['music-lyrics-obj']));
	}

	if ($obj['type'] == 'music' || $obj['type'] == 'music-lyrics') {
		return true;
	}
	return false;
}

?>
