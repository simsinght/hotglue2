/**
 *	modules/music/music-edit.js
 *	Frontend code for music player objects in edit mode
 */

$.glue.music = function() {
	return {
		/**
		 *	Refresh a music player object from backend
		 */
		refresh: function(obj) {
			var objId = $(obj).attr('id');
			$.glue.backend({
				method: 'glue.render_object',
				name: objId,
				edit: true
			}, function(html) {
				if (html) {
					var newObj = $(html);
					$(obj).replaceWith(newObj);
					$.glue.object.register(newObj);
				}
			});
		},

		/**
		 *	Parse ID3 tags from an audio file (client-side)
		 *	Reads ID3v2 (start of file) and ID3v1 (last 128 bytes)
		 *	callback(meta) where meta = { title, artist }
		 */
		_parseID3: function(file, callback) {
			var meta = { title: '', artist: '' };

			// Read first 4KB for ID3v2 + last 128 bytes for ID3v1
			var headerReader = new FileReader();
			headerReader.onload = function() {
				var buf = new Uint8Array(headerReader.result);

				// Try ID3v2 (starts with "ID3")
				if (buf.length >= 10 && buf[0] === 0x49 && buf[1] === 0x44 && buf[2] === 0x33) {
					var offset = 10;
					var major = buf[3]; // version
					// ID3v2 size (synchsafe integer)
					var size = ((buf[6] & 0x7f) << 21) | ((buf[7] & 0x7f) << 14) | ((buf[8] & 0x7f) << 7) | (buf[9] & 0x7f);
					var end = Math.min(10 + size, buf.length);

					while (offset + 10 <= end) {
						var frameId;
						var frameSize;
						if (major >= 3) {
							// ID3v2.3/v2.4: 4-char frame IDs
							frameId = String.fromCharCode(buf[offset], buf[offset+1], buf[offset+2], buf[offset+3]);
							if (major === 4) {
								frameSize = ((buf[offset+4] & 0x7f) << 21) | ((buf[offset+5] & 0x7f) << 14) | ((buf[offset+6] & 0x7f) << 7) | (buf[offset+7] & 0x7f);
							} else {
								frameSize = (buf[offset+4] << 24) | (buf[offset+5] << 16) | (buf[offset+6] << 8) | buf[offset+7];
							}
							offset += 10;
						} else {
							// ID3v2.2: 3-char frame IDs
							frameId = String.fromCharCode(buf[offset], buf[offset+1], buf[offset+2]);
							frameSize = (buf[offset+3] << 16) | (buf[offset+4] << 8) | buf[offset+5];
							offset += 6;
						}

						if (frameSize <= 0 || offset + frameSize > end) break;

						var isTitle = (frameId === 'TIT2' || frameId === 'TT2');
						var isArtist = (frameId === 'TPE1' || frameId === 'TP1');

						if (isTitle || isArtist) {
							var encoding = buf[offset];
							var textBytes = buf.slice(offset + 1, offset + frameSize);
							var text = '';
							if (encoding === 1 || encoding === 2) {
								// UTF-16
								var start = 0;
								if (textBytes.length >= 2 && ((textBytes[0] === 0xFF && textBytes[1] === 0xFE) || (textBytes[0] === 0xFE && textBytes[1] === 0xFF))) {
									start = 2;
								}
								var le = (start === 0 || textBytes[0] === 0xFF);
								for (var ci = start; ci + 1 < textBytes.length; ci += 2) {
									var code = le ? (textBytes[ci] | (textBytes[ci+1] << 8)) : ((textBytes[ci] << 8) | textBytes[ci+1]);
									if (code === 0) break;
									text += String.fromCharCode(code);
								}
							} else if (encoding === 3) {
								// UTF-8
								try { text = new TextDecoder("utf-8").decode(textBytes); } catch(e) {}
								text = text.replace(/\0/g, '');
							} else {
								// ISO-8859-1
								for (var ci = 0; ci < textBytes.length; ci++) {
									if (textBytes[ci] === 0) break;
									text += String.fromCharCode(textBytes[ci]);
								}
							}
							if (isTitle && text) meta.title = text;
							if (isArtist && text) meta.artist = text;
						}

						offset += frameSize;
					}
				}

				// If we got both from ID3v2, we're done
				if (meta.title && meta.artist) {
					callback(meta);
					return;
				}

				// Try ID3v1 (last 128 bytes)
				if (file.size >= 128) {
					var tailReader = new FileReader();
					tailReader.onload = function() {
						var tail = new Uint8Array(tailReader.result);
						// ID3v1 starts with "TAG"
						if (tail[0] === 0x54 && tail[1] === 0x41 && tail[2] === 0x47) {
							var readStr = function(arr, start, len) {
								var s = '';
								for (var ci = start; ci < start + len; ci++) {
									if (arr[ci] === 0) break;
									s += String.fromCharCode(arr[ci]);
								}
								return s.replace(/\s+$/, '');
							};
							if (!meta.title) meta.title = readStr(tail, 3, 30);
							if (!meta.artist) meta.artist = readStr(tail, 33, 30);
						}
						callback(meta);
					};
					tailReader.onerror = function() { callback(meta); };
					tailReader.readAsArrayBuffer(file.slice(file.size - 128));
				} else {
					callback(meta);
				}
			};
			headerReader.onerror = function() { callback(meta); };
			// Read first 4KB (enough for most ID3v2 title/artist frames)
			headerReader.readAsArrayBuffer(file.slice(0, 4096));
		},

		/**
		 *	Upload an audio file
		 *	callback(filename, meta) where meta = { title, artist }
		 */
		uploadAudio: function(obj, callback) {
			var self = this;
			var input = $('<input type="file" accept="audio/*" style="display:none">');
			$('body').append(input);

			$(input).bind('change', function(e) {
				var file = e.target.files[0];
				if (!file) {
					$(input).remove();
					return;
				}

				// Parse ID3 tags while uploading (in parallel)
				var parsedMeta = null;
				var uploadedFilename = null;
				var done = 0;

				function checkDone() {
					done++;
					if (done === 2 && callback) {
						callback(uploadedFilename, parsedMeta || { title: '', artist: '' });
					}
				}

				self._parseID3(file, function(meta) {
					parsedMeta = meta;
					// If upload already failed, don't proceed
					if (uploadedFilename !== false) checkDone();
				});

				var formData = new FormData();
				formData.append('file', file);
				formData.append('method', JSON.stringify('music.upload_audio'));
				formData.append('name', JSON.stringify($(obj).attr('id')));

				$.ajax({
					url: $.glue.base_url + 'json.php',
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					dataType: 'json',
					success: function(data) {
						if (data && !data['#error'] && data['#data'] && data['#data'].filename) {
							uploadedFilename = data['#data'].filename;
							checkDone();
						} else {
							uploadedFilename = false;
							$.glue.error(data && data['#data'] ? data['#data'] : 'Failed to upload audio file');
						}
					},
					error: function() {
						uploadedFilename = false;
						$.glue.error('Failed to upload audio file');
					}
				});

				$(input).remove();
			});

			$(input).click();
		},

		/**
		 *	Upload a cover image
		 */
		uploadCover: function(obj, callback) {
			var input = $('<input type="file" accept="image/*" style="display:none">');
			$('body').append(input);

			$(input).bind('change', function(e) {
				var file = e.target.files[0];
				if (!file) {
					$(input).remove();
					return;
				}

				var formData = new FormData();
				formData.append('file', file);
				formData.append('method', JSON.stringify('music.upload_cover'));
				formData.append('name', JSON.stringify($(obj).attr('id')));

				$.ajax({
					url: $.glue.base_url + 'json.php',
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					dataType: 'json',
					success: function(data) {
						if (data && !data['#error'] && data['#data'] && data['#data'].filename) {
							if (callback) callback(data['#data'].filename);
						} else {
							$.glue.error(data && data['#data'] ? data['#data'] : 'Failed to upload cover image');
						}
					},
					error: function() {
						$.glue.error('Failed to upload cover image');
					}
				});

				$(input).remove();
			});

			$(input).click();
		},

		/**
		 *	Upload a lyric video (mp4/webm)
		 */
		uploadVideo: function(obj, callback) {
			var input = $('<input type="file" accept="video/mp4,video/webm" style="display:none">');
			$('body').append(input);

			$(input).bind('change', function(e) {
				var file = e.target.files[0];
				if (!file) {
					$(input).remove();
					return;
				}

				var formData = new FormData();
				formData.append('file', file);
				formData.append('method', JSON.stringify('music.upload_video'));
				formData.append('name', JSON.stringify($(obj).attr('id')));

				$.ajax({
					url: $.glue.base_url + 'json.php',
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					dataType: 'json',
					success: function(data) {
						if (data && !data['#error'] && data['#data'] && data['#data'].filename) {
							if (callback) callback(data['#data'].filename);
						} else {
							$.glue.error(data && data['#data'] ? data['#data'] : 'Failed to upload video');
						}
					},
					error: function() {
						$.glue.error('Failed to upload video');
					}
				});

				$(input).remove();
			});

			$(input).click();
		},

		/**
		 *	Add a new track: upload audio, then set title/artist
		 *	Cover image can be added later via edit track
		 */
		addTrack: function(obj) {
			var self = this;

			// Step 1: Upload audio file (also parses ID3 tags)
			self.uploadAudio(obj, function(audioFilename, meta) {
				// Step 2: Enter title and artist (prefilled from ID3 tags)
				self._trackInfoDialog(obj, meta.title || '', meta.artist || '', function(title, artist) {
					// Save the new track
					$.glue.backend({
						method: 'music.get_data',
						name: $(obj).attr('id')
					}, function(data) {
						if (!data) {
							$.glue.error('Failed to load music data');
							return;
						}

						var tracks = data['tracks'] || [];
						tracks.push({
							title: title,
							artist: artist,
							audioFile: audioFilename,
							coverFile: ''
						});

						$.glue.backend({
							method: 'music.update_tracks',
							name: $(obj).attr('id'),
							tracks: JSON.stringify(tracks)
						}, function() {
							self.refresh(obj);
						});
					});
				});
			});
		},

		/**
		 *	Dialog to enter track title and artist
		 */
		_trackInfoDialog: function(obj, defaultTitle, defaultArtist, callback) {
			var html = '<div style="background:#fff;padding:20px;border-radius:5px;min-width:350px;">';
			html += '<h3 style="margin-top:0;">Track Info</h3>';
			html += '<div style="margin-bottom:12px;">';
			html += '<label style="display:block;margin-bottom:4px;">Title</label>';
			html += '<input type="text" class="music-input-title" value="' + (defaultTitle || '') + '" style="width:100%;padding:6px;box-sizing:border-box;">';
			html += '</div>';
			html += '<div style="margin-bottom:12px;">';
			html += '<label style="display:block;margin-bottom:4px;">Artist</label>';
			html += '<input type="text" class="music-input-artist" value="' + (defaultArtist || '') + '" style="width:100%;padding:6px;box-sizing:border-box;">';
			html += '</div>';
			html += '<div style="text-align:right;">';
			html += '<button class="music-info-cancel" style="padding:8px 16px;cursor:pointer;margin-right:8px;">Cancel</button>';
			html += '<button class="music-info-save" style="padding:8px 16px;cursor:pointer;">Save</button>';
			html += '</div>';
			html += '</div>';

			var overlay = $('<div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
			var dialog = $(html);
			$(overlay).append(dialog);
			$('body').append(overlay);

			$(dialog).bind('mousedown click', function(e) {
				e.stopPropagation();
			});

			$(dialog).find('.music-info-save').click(function(e) {
				e.stopPropagation();
				var title = $(dialog).find('.music-input-title').val() || 'Untitled';
				var artist = $(dialog).find('.music-input-artist').val() || 'Unknown';
				$(overlay).remove();
				if (callback) callback(title, artist);
			});

			$(dialog).find('.music-info-cancel').click(function(e) {
				e.stopPropagation();
				$(overlay).remove();
			});

			$(overlay).click(function(e) {
				if (e.target === this) {
					$(overlay).remove();
				}
			});
		},

		/**
		 *	Manage tracks dialog
		 */
		manageTracks: function(obj) {
			var self = this;

			$.glue.backend({
				method: 'music.get_data',
				name: $(obj).attr('id')
			}, function(data) {
				if (!data) {
					$.glue.error('Failed to load music data');
					return;
				}

				var tracks = data['tracks'] || [];

				var html = '<div class="music-manage-dialog" style="background:#fff;padding:20px;border-radius:5px;min-width:400px;max-height:80vh;overflow-y:auto;">';
				html += '<h3 style="margin-top:0;">Manage Tracks</h3>';
				html += '<div class="music-tracks-list" style="margin-bottom:15px;">';

				for (var i = 0; i < tracks.length; i++) {
					var track = tracks[i];
					var coverSrc = track.coverUrl || '';
					html += '<div class="music-track-item" data-index="' + i + '" style="display:flex;align-items:center;padding:8px;margin-bottom:8px;background:#f5f5f5;border-radius:4px;">';
					html += '<img src="' + coverSrc + '" style="width:40px;height:40px;object-fit:cover;margin-right:10px;border-radius:3px;background:#ddd;">';
					html += '<div style="flex:1;min-width:0;">';
					html += '<div style="font-size:14px;font-weight:bold;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + (track.title || 'Untitled') + '</div>';
					html += '<div style="font-size:12px;color:#666;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + (track.artist || 'Unknown') + '</div>';
					html += '</div>';
					html += '<button class="music-edit-track" data-index="' + i + '" style="margin-left:8px;cursor:pointer;">Edit</button>';
					html += '<button class="music-move-up" data-index="' + i + '" style="margin-left:4px;cursor:pointer;"' + (i === 0 ? ' disabled' : '') + '>&uarr;</button>';
					html += '<button class="music-move-down" data-index="' + i + '" style="margin-left:4px;cursor:pointer;"' + (i === tracks.length - 1 ? ' disabled' : '') + '>&darr;</button>';
					html += '<button class="music-remove-track" data-index="' + i + '" style="margin-left:4px;cursor:pointer;color:red;">&times;</button>';
					html += '</div>';
				}

				if (tracks.length === 0) {
					html += '<p style="color:#888;font-style:italic;">No tracks yet. Click "Add Track" to get started.</p>';
				}

				html += '</div>';
				html += '<div style="display:flex;justify-content:space-between;">';
				html += '<button class="music-add-track-btn" style="padding:8px 16px;cursor:pointer;">+ Add Track</button>';
				html += '<button class="music-manage-close" style="padding:8px 16px;cursor:pointer;">Close</button>';
				html += '</div>';
				html += '</div>';

				var overlay = $('<div class="music-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
				var dialog = $(html);
				$(overlay).append(dialog);
				$('body').append(overlay);

				$(dialog).bind('mousedown click', function(e) {
					e.stopPropagation();
				});

				// Close
				$(dialog).find('.music-manage-close').click(function(e) {
					e.stopPropagation();
					$(overlay).remove();
				});

				// Add track
				$(dialog).find('.music-add-track-btn').click(function(e) {
					e.stopPropagation();
					$(overlay).remove();
					self.addTrack(obj);
				});

				// Remove track
				$(dialog).find('.music-remove-track').click(function(e) {
					e.stopPropagation();
					var index = parseInt($(this).attr('data-index'));
					if (confirm('Remove "' + (tracks[index].title || 'Untitled') + '"?')) {
						tracks.splice(index, 1);
						$.glue.backend({
							method: 'music.update_tracks',
							name: $(obj).attr('id'),
							tracks: JSON.stringify(tracks)
						}, function() {
							$(overlay).remove();
							self.refresh(obj);
						});
					}
				});

				// Edit track
				$(dialog).find('.music-edit-track').click(function(e) {
					e.stopPropagation();
					var index = parseInt($(this).attr('data-index'));
					$(overlay).remove();
					self._editTrackDialog(obj, tracks, index);
				});

				// Move up
				$(dialog).find('.music-move-up').click(function(e) {
					e.stopPropagation();
					var index = parseInt($(this).attr('data-index'));
					if (index > 0) {
						var temp = tracks[index];
						tracks[index] = tracks[index - 1];
						tracks[index - 1] = temp;
						$.glue.backend({
							method: 'music.update_tracks',
							name: $(obj).attr('id'),
							tracks: JSON.stringify(tracks)
						}, function() {
							$(overlay).remove();
							self.refresh(obj);
							self.manageTracks(obj);
						});
					}
				});

				// Move down
				$(dialog).find('.music-move-down').click(function(e) {
					e.stopPropagation();
					var index = parseInt($(this).attr('data-index'));
					if (index < tracks.length - 1) {
						var temp = tracks[index];
						tracks[index] = tracks[index + 1];
						tracks[index + 1] = temp;
						$.glue.backend({
							method: 'music.update_tracks',
							name: $(obj).attr('id'),
							tracks: JSON.stringify(tracks)
						}, function() {
							$(overlay).remove();
							self.refresh(obj);
							self.manageTracks(obj);
						});
					}
				});

				$(overlay).click(function(e) {
					if (e.target === this) {
						$(overlay).remove();
					}
				});
			});
		},

		/**
		 *	Edit a single track's properties
		 */
		_editTrackDialog: function(obj, tracks, index) {
			var self = this;
			var track = tracks[index];

			var html = '<div style="background:#fff;padding:20px;border-radius:5px;min-width:350px;">';
			html += '<h3 style="margin-top:0;">Edit Track</h3>';
			html += '<div style="margin-bottom:12px;">';
			html += '<label style="display:block;margin-bottom:4px;">Title</label>';
			html += '<input type="text" class="music-edit-title" value="' + (track.title || '') + '" style="width:100%;padding:6px;box-sizing:border-box;">';
			html += '</div>';
			html += '<div style="margin-bottom:12px;">';
			html += '<label style="display:block;margin-bottom:4px;">Artist</label>';
			html += '<input type="text" class="music-edit-artist" value="' + (track.artist || '') + '" style="width:100%;padding:6px;box-sizing:border-box;">';
			html += '</div>';
			html += '<div style="margin-bottom:12px;">';
			html += '<button class="music-replace-audio" style="padding:6px 12px;cursor:pointer;">Replace Audio</button>';
			html += ' <button class="music-replace-cover" style="padding:6px 12px;cursor:pointer;">Replace Cover</button>';
			html += '</div>';
			html += '<div style="margin-bottom:12px;">';
			html += '<button class="music-upload-instrumental" style="padding:6px 12px;cursor:pointer;">Instrumental Track</button>';
			html += '<span class="music-instrumental-status" style="margin-left:8px;font-size:12px;color:' + (track.karaokeAudioFile ? '#2a2' : '#888') + ';">' + (track.karaokeAudioFile ? 'Added' : 'None') + '</span>';
			html += '</div>';
			html += '<div style="margin-bottom:12px;">';
			html += '<button class="music-upload-lyric-video" style="padding:6px 12px;cursor:pointer;">Lyric Video</button>';
			html += '<span class="music-lyric-video-status" style="margin-left:8px;font-size:12px;color:' + (track.lyricsVideoFile ? '#2a2' : '#888') + ';">' + (track.lyricsVideoFile ? 'Added' : 'None') + '</span>';
			html += '</div>';
			html += '<div style="text-align:right;">';
			html += '<button class="music-edit-cancel" style="padding:8px 16px;cursor:pointer;margin-right:8px;">Cancel</button>';
			html += '<button class="music-edit-save" style="padding:8px 16px;cursor:pointer;">Save</button>';
			html += '</div>';
			html += '</div>';

			var overlay = $('<div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
			var dialog = $(html);
			$(overlay).append(dialog);
			$('body').append(overlay);

			$(dialog).bind('mousedown click', function(e) {
				e.stopPropagation();
			});

			// Replace audio
			$(dialog).find('.music-replace-audio').click(function(e) {
				e.stopPropagation();
				self.uploadAudio(obj, function(filename) {
					track.audioFile = filename;
				});
			});

			// Replace cover
			$(dialog).find('.music-replace-cover').click(function(e) {
				e.stopPropagation();
				self.uploadCover(obj, function(filename) {
					track.coverFile = filename;
				});
			});

			// Upload instrumental track
			$(dialog).find('.music-upload-instrumental').click(function(e) {
				e.stopPropagation();
				self.uploadAudio(obj, function(filename) {
					track.karaokeAudioFile = filename;
					$(dialog).find('.music-instrumental-status').text('Added').css('color', '#2a2');
				});
			});

			// Upload lyric video
			$(dialog).find('.music-upload-lyric-video').click(function(e) {
				e.stopPropagation();
				self.uploadVideo(obj, function(filename) {
					track.lyricsVideoFile = filename;
					$(dialog).find('.music-lyric-video-status').text('Added').css('color', '#2a2');
				});
			});

			// Save
			$(dialog).find('.music-edit-save').click(function(e) {
				e.stopPropagation();
				track.title = $(dialog).find('.music-edit-title').val() || 'Untitled';
				track.artist = $(dialog).find('.music-edit-artist').val() || 'Unknown';
				tracks[index] = track;

				$.glue.backend({
					method: 'music.update_tracks',
					name: $(obj).attr('id'),
					tracks: JSON.stringify(tracks)
				}, function() {
					$(overlay).remove();
					self.refresh(obj);
				});
			});

			// Cancel
			$(dialog).find('.music-edit-cancel').click(function(e) {
				e.stopPropagation();
				$(overlay).remove();
				self.manageTracks(obj);
			});

			$(overlay).click(function(e) {
				if (e.target === this) {
					$(overlay).remove();
					self.manageTracks(obj);
				}
			});
		},

		/**
		 *	Manage lyrics for tracks — fetch from lrclib.net or paste LRC
		 */
		manageLyrics: function(obj) {
			var self = this;
			var objId = $(obj).attr('id');

			$.glue.backend({
				method: 'music.get_data',
				name: objId
			}, function(data) {
				if (!data) {
					$.glue.error('Failed to load music data');
					return;
				}

				var tracks = data['tracks'] || [];
				if (tracks.length === 0) {
					$.glue.error('Add some tracks first');
					return;
				}

				var html = '<div class="music-lyrics-dialog" style="background:#fff;padding:20px;border-radius:5px;min-width:420px;max-height:80vh;overflow-y:auto;">';
				html += '<h3 style="margin-top:0;">Manage Lyrics</h3>';

				for (var i = 0; i < tracks.length; i++) {
					var t = tracks[i];
					var hasLrc = !!(t.lrc);
					html += '<div class="music-lyrics-track" data-index="' + i + '" style="padding:10px;margin-bottom:10px;background:#f5f5f5;border-radius:4px;">';
					html += '<div style="font-weight:bold;margin-bottom:6px;">' + (t.title || 'Untitled') + ' — ' + (t.artist || 'Unknown') + '</div>';
					html += '<div style="margin-bottom:6px;color:' + (hasLrc ? '#2a2' : '#888') + ';font-size:13px;">';
					if (hasLrc) {
						var lineCount = t.lrc.split('\n').filter(function(l) { return l.match(/^\[/); }).length;
						html += 'Synced lyrics (' + lineCount + ' lines)';
					} else {
						html += 'No lyrics';
					}
					html += '</div>';
					html += '<button class="music-lrc-fetch" data-index="' + i + '" style="padding:4px 10px;cursor:pointer;margin-right:4px;">Fetch</button>';
					html += '<button class="music-lrc-paste" data-index="' + i + '" style="padding:4px 10px;cursor:pointer;margin-right:4px;">Paste</button>';
					if (hasLrc) {
						html += '<button class="music-lrc-clear" data-index="' + i + '" style="padding:4px 10px;cursor:pointer;color:red;">Clear</button>';
						var offset = t.lrcOffset || 0;
						html += '<div style="display:inline-flex;align-items:center;margin-left:12px;font-size:12px;color:#666;">';
						html += '<span>Offset:</span>';
						html += '<button class="music-lrc-offset-minus" data-index="' + i + '" style="padding:2px 6px;cursor:pointer;margin-left:4px;">−</button>';
						html += '<span class="music-lrc-offset-val" data-index="' + i + '" style="min-width:42px;text-align:center;font-family:monospace;">' + (offset >= 0 ? '+' : '') + offset.toFixed(1) + 's</span>';
						html += '<button class="music-lrc-offset-plus" data-index="' + i + '" style="padding:2px 6px;cursor:pointer;">+</button>';
						html += '</div>';
					}
					html += '</div>';
				}

				html += '<div style="display:flex;justify-content:space-between;margin-top:15px;">';
				html += '<button class="music-lrc-screen-btn" style="padding:8px 16px;cursor:pointer;">Show Lyrics Screen</button>';
				html += '<button class="music-lrc-close" style="padding:8px 16px;cursor:pointer;">Close</button>';
				html += '</div></div>';

				var overlay = $('<div class="music-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
				var dialog = $(html);
				$(overlay).append(dialog);
				$('body').append(overlay);

				$(dialog).bind('mousedown click', function(e) { e.stopPropagation(); });

				// Close
				$(dialog).find('.music-lrc-close').click(function(e) {
					e.stopPropagation();
					$(overlay).remove();
				});

				$(overlay).click(function(e) {
					if (e.target === this) $(overlay).remove();
				});

				// Fetch lyrics from lrclib.net
				$(dialog).find('.music-lrc-fetch').click(function(e) {
					e.stopPropagation();
					var idx = parseInt($(this).attr('data-index'));
					var track = tracks[idx];
					var btn = $(this);
					btn.text('Searching...').prop('disabled', true);

					$.glue.backend({
						method: 'music.fetch_lyrics',
						track_name: track.title || '',
						artist_name: track.artist || ''
					}, function(results) {
						btn.text('Fetch').prop('disabled', false);
						if (!results || results.length === 0) {
							alert('No synced lyrics found for "' + (track.title || '') + '"');
							return;
						}
						// Show results picker
						$(overlay).remove();
						self._lrcPickerDialog(obj, tracks, idx, results);
					});
				});

				// Paste LRC
				$(dialog).find('.music-lrc-paste').click(function(e) {
					e.stopPropagation();
					var idx = parseInt($(this).attr('data-index'));
					$(overlay).remove();
					self._lrcPasteDialog(obj, tracks, idx);
				});

				// Clear lyrics
				$(dialog).find('.music-lrc-clear').click(function(e) {
					e.stopPropagation();
					var idx = parseInt($(this).attr('data-index'));
					if (confirm('Clear lyrics for "' + (tracks[idx].title || '') + '"?')) {
						$.glue.backend({
							method: 'music.save_lrc',
							name: objId,
							track_index: idx,
							lrc: ''
						}, function() {
							$(overlay).remove();
							self.refresh(obj);
							self.manageLyrics(obj);
						});
					}
				});

				// Offset +/- buttons
				$(dialog).find('.music-lrc-offset-minus, .music-lrc-offset-plus').click(function(e) {
					e.stopPropagation();
					var idx = parseInt($(this).attr('data-index'));
					var delta = $(this).hasClass('music-lrc-offset-plus') ? 0.5 : -0.5;
					var current = tracks[idx].lrcOffset || 0;
					var newVal = Math.round((current + delta) * 10) / 10;
					tracks[idx].lrcOffset = newVal;
					// Update display
					var label = $(dialog).find('.music-lrc-offset-val[data-index="' + idx + '"]');
					label.text((newVal >= 0 ? '+' : '') + newVal.toFixed(1) + 's');
					// Save
					$.glue.backend({
						method: 'music.update_tracks',
						name: objId,
						tracks: JSON.stringify(tracks)
					});
				});

				// Show/create lyrics screen
				$(dialog).find('.music-lrc-screen-btn').click(function(e) {
					e.stopPropagation();
					$(overlay).remove();
					self._ensureLyricsScreen(obj);
				});
			});
		},

		/**
		 *	Show search results from lrclib.net and let user pick one
		 */
		_lrcPickerDialog: function(obj, tracks, trackIndex, results) {
			var self = this;
			var objId = $(obj).attr('id');

			var html = '<div style="background:#fff;padding:20px;border-radius:5px;min-width:420px;max-height:80vh;overflow-y:auto;">';
			html += '<h3 style="margin-top:0;">Select Lyrics</h3>';
			html += '<p style="color:#666;font-size:13px;">Found ' + results.length + ' version(s) with synced lyrics:</p>';

			for (var i = 0; i < results.length; i++) {
				var r = results[i];
				var dur = r.duration ? Math.floor(r.duration / 60) + ':' + ('0' + Math.floor(r.duration % 60)).slice(-2) : '?';
				html += '<div class="music-lrc-result" data-index="' + i + '" style="padding:10px;margin-bottom:8px;background:#f5f5f5;border-radius:4px;cursor:pointer;border:2px solid transparent;">';
				html += '<div style="font-weight:bold;">' + (r.trackName || '') + '</div>';
				html += '<div style="font-size:13px;color:#666;">' + (r.artistName || '') + ' — ' + (r.albumName || '') + ' (' + dur + ')</div>';
				html += '</div>';
			}

			html += '<div style="text-align:right;margin-top:15px;">';
			html += '<button class="music-lrc-pick-cancel" style="padding:8px 16px;cursor:pointer;">Cancel</button>';
			html += '</div></div>';

			var overlay = $('<div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
			var dialog = $(html);
			$(overlay).append(dialog);
			$('body').append(overlay);

			$(dialog).bind('mousedown click', function(e) { e.stopPropagation(); });

			$(dialog).find('.music-lrc-result').click(function(e) {
				e.stopPropagation();
				var idx = parseInt($(this).attr('data-index'));
				$.glue.backend({
					method: 'music.save_lrc',
					name: objId,
					track_index: trackIndex,
					lrc: results[idx].syncedLyrics
				}, function() {
					$(overlay).remove();
					self.refresh(obj);
					self.manageLyrics(obj);
				});
			});

			$(dialog).find('.music-lrc-pick-cancel').click(function(e) {
				e.stopPropagation();
				$(overlay).remove();
				self.manageLyrics(obj);
			});

			$(overlay).click(function(e) {
				if (e.target === this) {
					$(overlay).remove();
					self.manageLyrics(obj);
				}
			});
		},

		/**
		 *	Dialog to paste LRC text manually
		 */
		_lrcPasteDialog: function(obj, tracks, trackIndex) {
			var self = this;
			var objId = $(obj).attr('id');
			var existing = tracks[trackIndex].lrc || '';

			var html = '<div style="background:#fff;padding:20px;border-radius:5px;min-width:450px;">';
			html += '<h3 style="margin-top:0;">Paste LRC Lyrics</h3>';
			html += '<p style="color:#666;font-size:13px;margin-bottom:8px;">Paste synced lyrics in LRC format ([mm:ss.xx] text):</p>';
			html += '<textarea class="music-lrc-textarea" style="width:100%;height:300px;font-family:monospace;font-size:12px;padding:8px;box-sizing:border-box;resize:vertical;">' + existing + '</textarea>';
			html += '<div style="text-align:right;margin-top:12px;">';
			html += '<button class="music-lrc-paste-cancel" style="padding:8px 16px;cursor:pointer;margin-right:8px;">Cancel</button>';
			html += '<button class="music-lrc-paste-save" style="padding:8px 16px;cursor:pointer;">Save</button>';
			html += '</div></div>';

			var overlay = $('<div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
			var dialog = $(html);
			$(overlay).append(dialog);
			$('body').append(overlay);

			$(dialog).bind('mousedown click', function(e) { e.stopPropagation(); });

			$(dialog).find('.music-lrc-paste-save').click(function(e) {
				e.stopPropagation();
				var lrc = $(dialog).find('.music-lrc-textarea').val().trim();
				$.glue.backend({
					method: 'music.save_lrc',
					name: objId,
					track_index: trackIndex,
					lrc: lrc || ''
				}, function() {
					$(overlay).remove();
					self.refresh(obj);
					self.manageLyrics(obj);
				});
			});

			$(dialog).find('.music-lrc-paste-cancel').click(function(e) {
				e.stopPropagation();
				$(overlay).remove();
				self.manageLyrics(obj);
			});

			$(overlay).click(function(e) {
				if (e.target === this) {
					$(overlay).remove();
					self.manageLyrics(obj);
				}
			});
		},

		/**
		 *	Create or show the lyrics screen object
		 */
		_ensureLyricsScreen: function(obj) {
			var self = this;
			var objId = $(obj).attr('id');
			var existingId = $(obj).attr('data-music-lyrics-obj');

			if (existingId) {
				// Lyrics screen already exists — make sure it's visible
				var el = document.getElementById(existingId);
				if (el) {
					$(el).show();
					$.glue.sel.select($(el));
				}
				return;
			}

			// Create new lyrics screen next to the player
			var playerPos = $(obj).position();
			var playerWidth = $(obj).outerWidth();
			var x = (playerPos.left + playerWidth + 20) + 'px';
			var y = playerPos.top + 'px';

			$.glue.backend({
				method: 'music.create_lyrics',
				page: $.glue.page,
				parent: objId,
				x: x,
				y: y
			}, function(html) {
				if (html) {
					var newElem = $(html);
					$('body').append(newElem);
					$.glue.object.register(newElem);
					$.glue.sel.select(newElem);
					// Update parent's data attribute
					$(obj).attr('data-music-lyrics-obj', newElem.attr('id'));
					// Re-render the parent to pick up the link
					self.refresh(obj);
				}
			});
		},

		/**
		 *	Adjust lyrics font size by delta pixels
		 */
		adjustLyricsFontSize: function(obj, delta) {
			var current = parseInt($(obj).attr('data-music-lrc-fontsize')) || 20;
			var next = Math.max(8, Math.min(80, current + delta));
			if (next === current) return;

			$.glue.backend({
				method: 'music.set_lyrics_fontsize',
				name: $(obj).attr('id'),
				fontsize: next
			}, function(html) {
				if (html) {
					var newObj = $(html);
					$(obj).replaceWith(newObj);
					$.glue.object.register(newObj);
					$.glue.sel.select(newObj);
				}
			});
		},

		/**
		 *	Cycle through size presets: sm → md → lg → sm
		 */
		cycleSize: function(obj) {
			var self = this;
			var current = $(obj).attr('data-music-size') || 'md';
			var order = ['sm', 'md', 'lg', 'vt'];
			var next = order[(order.indexOf(current) + 1) % order.length];

			$.glue.backend({
				method: 'music.set_size',
				name: $(obj).attr('id'),
				size: next
			}, function(html) {
				if (html) {
					var newObj = $(html);
					$(obj).replaceWith(newObj);
					$.glue.object.register(newObj);
					$.glue.sel.select(newObj);
				}
			});
		},

		/**
		 *	Configure Karaoke API URL for live sing-along
		 */
		karaokeApiUrl: function(obj) {
			var objId = $(obj).attr('id');

			$.glue.backend({ method: 'glue.load_object', name: objId }, function(data) {
				var currentUrl = data['music-karaoke-api'] || '';

				var html = '<div style="background:#fff;padding:20px;border-radius:5px;min-width:350px;">';
				html += '<h3 style="margin-top:0;">Karaoke API URL</h3>';
				html += '<p style="color:#666;font-size:13px;margin-bottom:12px;">Set the signaling server URL for live sing-along (e.g. "https://api.example.com/api/karaoke").</p>';
				html += '<div style="margin-bottom:14px;">';
				html += '<input type="text" class="music-karaoke-api-input" value="' + currentUrl + '" placeholder="https://api.example.com/api/karaoke" style="width:100%;padding:6px;box-sizing:border-box;font-size:14px;">';
				html += '</div>';
				html += '<div style="text-align:right;">';
				html += '<button class="music-karaoke-cancel" style="padding:8px 16px;cursor:pointer;margin-right:8px;">Cancel</button>';
				html += '<button class="music-karaoke-save" style="padding:8px 16px;cursor:pointer;">Save</button>';
				html += '</div></div>';

				var overlay = $('<div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
				var dialog = $(html);
				$(overlay).append(dialog);
				$('body').append(overlay);

				$(dialog).bind('mousedown click', function(e) { e.stopPropagation(); });

				$(dialog).find('.music-karaoke-save').click(function(e) {
					e.stopPropagation();
					var url = $(dialog).find('.music-karaoke-api-input').val().trim();

					var update = { method: 'glue.update_object', name: objId };
					update['music-karaoke-api'] = url;

					$.glue.backend(update, function() {
						$(overlay).remove();
						$.glue.music.refresh(obj);
					});
				});

				$(dialog).find('.music-karaoke-cancel').click(function(e) {
					e.stopPropagation();
					$(overlay).remove();
				});

				$(overlay).click(function(e) {
					if (e.target === this) $(overlay).remove();
				});
			}, false);
		},

		/**
		 *	Configure prev/next page navigation links
		 */
		pageLinks: function(obj) {
			var self = this;
			var objId = $(obj).attr('id');

			$.glue.backend({ method: 'glue.load_object', name: objId }, function(data) {
				var prevPage = data['music-prev-page'] || '';
				var nextPage = data['music-next-page'] || '';

				var html = '<div style="background:#fff;padding:20px;border-radius:5px;min-width:350px;">';
				html += '<h3 style="margin-top:0;">Page Navigation</h3>';
				html += '<p style="color:#666;font-size:13px;margin-bottom:12px;">Set prev/next pages for playlist navigation. Use the page name (e.g. "housewarming") or a full URL.</p>';
				html += '<div style="margin-bottom:12px;">';
				html += '<label style="display:block;margin-bottom:4px;font-size:13px;color:#666;">Previous page</label>';
				html += '<input type="text" class="music-prev-page-input" value="' + prevPage + '" placeholder="e.g. housewarming" style="width:100%;padding:6px;box-sizing:border-box;font-size:14px;">';
				html += '</div>';
				html += '<div style="margin-bottom:14px;">';
				html += '<label style="display:block;margin-bottom:4px;font-size:13px;color:#666;">Next page</label>';
				html += '<input type="text" class="music-next-page-input" value="' + nextPage + '" placeholder="e.g. housewarmed" style="width:100%;padding:6px;box-sizing:border-box;font-size:14px;">';
				html += '</div>';
				html += '<div style="text-align:right;">';
				html += '<button class="music-page-cancel" style="padding:8px 16px;cursor:pointer;margin-right:8px;">Cancel</button>';
				html += '<button class="music-page-save" style="padding:8px 16px;cursor:pointer;">Save</button>';
				html += '</div></div>';

				var overlay = $('<div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:10000;"></div>');
				var dialog = $(html);
				$(overlay).append(dialog);
				$('body').append(overlay);

				$(dialog).bind('mousedown click', function(e) { e.stopPropagation(); });

				$(dialog).find('.music-page-save').click(function(e) {
					e.stopPropagation();
					var prev = $(dialog).find('.music-prev-page-input').val().trim();
					var next = $(dialog).find('.music-next-page-input').val().trim();

					var update = { method: 'glue.update_object', name: objId };
					update['music-prev-page'] = prev;
					update['music-next-page'] = next;

					$.glue.backend(update, function() {
						$(overlay).remove();
						self.refresh(obj);
					});
				});

				$(dialog).find('.music-page-cancel').click(function(e) {
					e.stopPropagation();
					$(overlay).remove();
				});

				$(overlay).click(function(e) {
					if (e.target === this) $(overlay).remove();
				});
			}, false);
		}
	};
}();


// Prevent click-through to page in edit mode for control buttons
$('.music-player').live('click', function(e) {
	if ($(e.target).closest('.music-controls').length > 0) {
		e.stopPropagation();
	}
});


$(document).ready(function() {
	var elem;

	//
	// Context menu items
	//

	// Manage tracks button
	elem = $('<img src="' + $.glue.base_url + 'modules/music/music-manage.png" alt="btn" title="manage tracks" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.music.manageTracks(obj);
	});
	$.glue.contextmenu.register('music-player', 'music-manage', elem);

	// Cycle size button
	elem = $('<img src="' + $.glue.base_url + 'modules/music/music-size.png" alt="btn" title="cycle size (sm/md/lg)" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.music.cycleSize(obj);
	});
	$.glue.contextmenu.register('music-player', 'music-size', elem);

	// Lyrics / karaoke button
	elem = $('<img src="' + $.glue.base_url + 'modules/music/music-lyrics.png" alt="btn" title="lyrics / karaoke" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.music.manageLyrics(obj);
	});
	$.glue.contextmenu.register('music-player', 'music-lyrics', elem);

	// Page navigation links
	elem = $('<img src="' + $.glue.base_url + 'modules/object/object-link.png" alt="btn" title="prev/next page links" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.music.pageLinks(obj);
	});
	$.glue.contextmenu.register('music-player', 'music-page-links', elem);

	// Karaoke API URL
	elem = $('<img src="' + $.glue.base_url + 'modules/object/object-link.png" alt="btn" title="karaoke API URL" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.music.karaokeApiUrl(obj);
	});
	$.glue.contextmenu.register('music-player', 'music-karaoke-api', elem);

	// Add track button
	elem = $('<img src="' + $.glue.base_url + 'modules/music/music-add.png" alt="btn" title="add track" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.music.addTrack(obj);
	});
	$.glue.contextmenu.register('music-player', 'music-add', elem);

	//
	// Lyrics screen context menu — font size +/-
	//
	elem = $('<img src="' + $.glue.base_url + 'modules/music/music-size.png" alt="btn" title="increase text size" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.music.adjustLyricsFontSize(obj, 2);
	});
	$.glue.contextmenu.register('music-lyrics-screen', 'music-lrc-size-up', elem);

	elem = $('<img src="' + $.glue.base_url + 'modules/music/music-size.png" alt="btn" title="decrease text size" width="32" height="32" style="opacity:0.5;">');
	$(elem).bind('click', function(e) {
		var obj = $(this).data('owner');
		$.glue.music.adjustLyricsFontSize(obj, -2);
	});
	$.glue.contextmenu.register('music-lyrics-screen', 'music-lrc-size-down', elem);

	//
	// Create menu item
	//
	elem = $('<img src="' + $.glue.base_url + 'modules/music/music-new.png" alt="btn" title="new music player" width="32" height="32">');
	$(elem).bind('click', function(e) {
		var clickX = e.pageX;
		var clickY = e.pageY;
		$.glue.menu.hide();

		$.glue.backend({
			method: 'music.create',
			page: $.glue.page,
			x: clickX + 'px',
			y: clickY + 'px'
		}, function(html) {
			if (html) {
				var newElem = $(html);
				$('body').append(newElem);
				$.glue.object.register(newElem);
				$.glue.sel.select(newElem);
			}
		});
	});
	$.glue.menu.register('new', elem);

	// Lyrics toggle in edit mode — show/hide the lyrics screen object
	$('.music-lyrics-toggle').each(function() {
		var btn = this;
		var player = $(btn).closest('.music-player');
		var screenId = player.attr('data-music-lyrics-obj');
		if (!screenId) return;

		btn.addEventListener('click', function(e) {
			e.stopPropagation();
			e.preventDefault();
			var screen = document.getElementById(screenId);
			if (!screen) return;
			var visible = screen.style.display !== 'none';
			screen.style.display = visible ? 'none' : '';
			btn.classList.toggle('music-lyrics-on', !visible);
		});
	});
});
