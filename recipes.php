<?php include_once('includes/header.php'); ?>

<?php

	error_reporting(0);

    // delete selected records
    if(isset($_POST['submit'])) {

        $arr = $_POST['chk_id'];
        $count = count($arr);
        if ($count > 0) {
            foreach ($arr as $recipe_id) {

                $sql_image = "SELECT recipe_image FROM tbl_recipes WHERE recipe_id = $recipe_id";
                $img_results = mysqli_query($connect, $sql_image);

                $sql_delete = "DELETE FROM tbl_recipes WHERE recipe_id = $recipe_id";

                if (mysqli_query($connect, $sql_delete)) {
                    while ($row = mysqli_fetch_assoc($img_results)) {
                        unlink('upload/' . $row['recipe_image']);
                    }
                    $_SESSION['msg'] = "$count Selected recipes deleted";
                } else {
                    $_SESSION['msg'] = "Error deleting record";
                }

            }
        } else {
            $_SESSION['msg'] = "Whoops! no recipes selected to delete";
        }
        header("Location:recipes.php");
        exit;
    } 

	if (isset($_REQUEST['keyword']) && $_REQUEST['keyword']<>"") {
		$keyword = $_REQUEST['keyword'];
		$reload = "recipes.php";
		$sql =  "SELECT n.*, c.* FROM tbl_recipes n LEFT JOIN tbl_category c ON n.cat_id = c.cid
					WHERE n.recipe_title LIKE '%$keyword%'
					GROUP BY n.recipe_id  
					ORDER BY n.recipe_id DESC";
		$result = $connect->query($sql);
	} else {
		$reload = "recipes.php";
		$sql =  "SELECT n.*, c.* FROM tbl_recipes n LEFT JOIN tbl_category c ON n.cat_id = c.cid
					GROUP BY n.recipe_id  
					ORDER BY n.recipe_id DESC";
		$result = $connect->query($sql);
	}

	$rpp = $postPerPage;
	$page = intval($_GET["page"]);
	if($page <= 0) $page = 1;  
	$tcount = mysqli_num_rows($result);
	$tpages = ($tcount) ? ceil($tcount / $rpp) : 1;
	$count = 0;
	$i = ($page-1) * $rpp;
	$no_urut = ($page-1) * $rpp;	

	$now = new DateTime();
	$lastUpdate = $now->format('Y-m-d H:i:s');

    if (isset($_GET['add'])) {
    	if ($total_featured >= 10) {
			$_SESSION['msg'] = "You have reached the maximum number of featured recipes!";
			header("Location:recipes.php");
			exit;
    	} else {	
    		$data = array(
				'featured' => '1',
				'last_update' => $lastUpdate
			);	
			$result = update('tbl_recipes', $data, "WHERE recipe_id = '".$_GET['add']."'");
			if ($result > 0) {
				$_SESSION['msg'] = "Success added to featured recipes";
				header("Location:recipes.php");
				exit;
			}
		}
    }

    if (isset($_GET['remove'])) {
		$data = array('featured' => '0');	
		$result = update('tbl_recipes', $data, "WHERE recipe_id = '".$_GET['remove']."'");
		if ($result > 0) {
			$_SESSION['msg'] = "Removed from featured recipes";
			header("Location:recipes.php");
			exit;
		}
    }

?>

<section class="content">

	<ol class="breadcrumb">
		<li><a href="dashboard.php">Dashboard</a></li>
		<li class="active">Manage Recipes</a></li>
	</ol>

	<div class="container-fluid">

		<div class="row clearfix">
			<div class="col-lg-12 col-md-12 col-sm-12 col-xs-12">
				<div class="card corner-radius">
					<div class="header">
						<h2>MANAGE RECIPES</h2>
						<div class="header-dropdown m-r--5">
							<?php if (defined('ENABLE_STUDIO_FEATURES') && ENABLE_STUDIO_FEATURES): ?>
							<a href="ai-generator.php"><button type="button" class="button button-rounded btn-offset waves-effect waves-float" style="background:#673ab7;color:#fff;"><i class="material-icons" style="font-size:14px;vertical-align:middle;">auto_awesome</i> AI STUDIO FOTO</button></a>
							<?php endif; ?>
							<a href="recipes-add.php"><button type="button" class="button button-rounded btn-offset waves-effect waves-float">ADD NEW RECIPES</button></a>
						</div>
					</div>

					<div style="margin-top: -10px;" class="body table-responsive">

						<?php if(isset($_SESSION['msg'])) { ?>
						<div class='alert alert-info alert-dismissible corner-radius bottom-offset' role='alert'>
							<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span>&nbsp;&nbsp;</button>
							<?php echo $_SESSION['msg']; ?>
						</div>
						<?php unset($_SESSION['msg']); } ?>

						<form method="get" id="form_validation">
							<table class='table'>
								<tr>
									<td>
										<div class="form-group form-float">
											<div class="form-line">
												<input type="text" class="form-control" name="keyword" placeholder="Search..." required>
											</div>
										</div>
									</td>
									<td width="1%"><a href="recipes.php"><button type="button" class="button button-rounded waves-effect waves-float">RESET</button></a></td>
									<td width="1%"><button type="submit" class="btn bg-blue btn-circle waves-effect waves-circle waves-float"><i class="material-icons">search</i></button></td>
								</tr>
							</table>
						</form>

						<?php if ($tcount == 0) { ?>
							<p align="center" style="font-size: 110%;">There are no recipes.</p>
						<?php } else { ?>

						<form method="post" action="" id="recipes-batch-form">

							<div style="margin-left: 8px; margin-top: -36px; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
								<button type="submit" name="submit" id="submit" class="button button-rounded waves-effect waves-float" onclick="return confirm('Are you sure want to delete all selected recipes?')">Delete selected items(s)</button>
								<?php if (defined('ENABLE_STUDIO_FEATURES') && ENABLE_STUDIO_FEATURES): ?>
								<button type="button" id="btn-sync-batch" class="button button-rounded waves-effect waves-float" style="background:#00bcd4;color:#fff;"><i class="material-icons" style="font-size:14px;vertical-align:middle;">cloud_upload</i> 🚀 Push Terpilih ke Web Hosting</button>
								<button type="button" id="btn-test-sync" class="button button-rounded waves-effect waves-float" style="background:#607d8b;color:#fff;" title="Cek koneksi ke live hosting airfry.web.id"><i class="material-icons" style="font-size:14px;vertical-align:middle;">network_check</i> Tes Koneksi Live</button>
								<?php endif; ?>
							</div>				

							<table class='table table-hover table-striped'>
								<thead>
									<tr>
										<th width="1%">
											<div style="margin-bottom: -15px">
												<input id="chk_all" name="chk_all" type="checkbox" class="filled-in chk-col-blue" />
												<label for="chk_all"></label>
											</div>
										</th>
										<th width="28%">Recipe Name</th>
										<th width="1%">Image</th>
										<th width="12%">Time</th>
										<th width="10%">Category</th>
										<th width="5%">Featured</th>
										<th width="5%"><center>View</center></th>
										<th width="5%"><center>Type</center></th>
										<?php if (defined('ENABLE_STUDIO_FEATURES') && ENABLE_STUDIO_FEATURES): ?>
										<th width="14%"><center>Live Hosting</center></th>
										<?php endif; ?>
										<th width="<?php echo (defined('ENABLE_STUDIO_FEATURES') && ENABLE_STUDIO_FEATURES) ? '19%' : '25%'; ?>"><center>Action</center></th>
									</tr>
								</thead>
								<?php
								while(($count < $rpp) && ($i < $tcount)) {
									mysqli_data_seek($result, $i);
									$data = mysqli_fetch_array($result);
									?>
									<tr id="recipe-row-<?php echo $data['recipe_id'];?>">

										<td style="vertical-align: middle;" width="1%">
											<div style="margin-top: 10px;">
												<input type="checkbox" name="chk_id[]" id="<?php echo $data['recipe_id'];?>" class="chkbox filled-in chk-col-blue" value="<?php echo $data['recipe_id'];?>"/>
					                            <label for="<?php echo $data['recipe_id'];?>"></label>
											</div>
										</td>

										<td style="vertical-align: middle;"><strong><?php echo htmlspecialchars($data['recipe_title']);?></strong></td>

										<td style="vertical-align: middle;">
											<?php
											if ($data['content_type'] == 'youtube') { ?>
												<img class="img-corner-radius" style="object-fit:cover;" src="https://img.youtube.com/vi/<?php echo $data['video_id'];?>/mqdefault.jpg" height="60px" width="80px"/>
											<?php } else { ?>
												<img class="img-corner-radius" style="object-fit:cover;" src="upload/<?php echo $data['recipe_image'];?>" height="60px" width="80px"/>
											<?php } ?>
										</td>

										<td style="vertical-align: middle;"><?php echo $data['recipe_time'];?></td>
										<td style="vertical-align: middle;"><?php echo $data['category_name'];?></td>

										<td style="vertical-align: middle;"><center>
							            		<?php if ($data['featured'] == '0') { ?>
							            			<a href="recipes.php?add=<?php echo $data['recipe_id'];?>" onclick="return confirm('Add to featured recipes?')" ><i class="material-icons" style="color:grey">lens</i></a>
							            		<?php } else { ?>
							            			<a href="recipes.php?remove=<?php echo $data['recipe_id'];?>" onclick="return confirm('Remove from featured recipes?')" ><i class="material-icons" style="color:#2196f3">lens</i></a>
							            		<?php } ?>
										</center></td>

										<td style="vertical-align: middle;"><center><?php echo $data['total_views'];?></center></td>
										<td style="vertical-align: middle;"><center>
											<?php if ($data['content_type'] == 'Post') { ?>
											<span class="label label-rounded bg-blue">RECIPE</span>
											<?php } else { ?>
											<span class="label label-rounded bg-orange">VIDEO</span>
											<?php } ?>	
										</center></td>

										<!-- Live Hosting Sync Column (Only for Local Studio) -->
										<?php if (defined('ENABLE_STUDIO_FEATURES') && ENABLE_STUDIO_FEATURES): ?>
										<td style="vertical-align: middle;" class="text-center" id="sync-cell-<?php echo $data['recipe_id'];?>">
											<?php if (!empty($data['live_recipe_id'])) { ?>
												<span class="label bg-green" title="Terakhir disinkron: <?php echo $data['last_synced_at'] ?? '-';?>">✓ Live #<?php echo $data['live_recipe_id'];?></span>
												<div style="margin-top: 6px;">
													<button type="button" class="btn btn-xs bg-indigo waves-effect btn-push-single" data-id="<?php echo $data['recipe_id'];?>" data-title="<?php echo htmlspecialchars($data['recipe_title'], ENT_QUOTES);?>" title="Sync ulang ke Web Hosting">
														<i class="material-icons" style="font-size:12px;vertical-align:middle;">sync</i> Sync Ulang
													</button>
												</div>
											<?php } else { ?>
												<span class="label bg-grey">Hanya Lokal</span>
												<div style="margin-top: 6px;">
													<button type="button" class="btn btn-xs bg-cyan waves-effect btn-push-single" data-id="<?php echo $data['recipe_id'];?>" data-title="<?php echo htmlspecialchars($data['recipe_title'], ENT_QUOTES);?>" title="Push resep & foto ke hosting airfry.web.id">
														<i class="material-icons" style="font-size:12px;vertical-align:middle;">cloud_upload</i> 🚀 Push
													</button>
												</div>
											<?php } ?>
										</td>
										<?php endif; ?>

										<td style="vertical-align: middle;"><center>

											<?php if (defined('ENABLE_STUDIO_FEATURES') && ENABLE_STUDIO_FEATURES): ?>
											<a href="javascript:void(0)" class="btn-push-single" data-id="<?php echo $data['recipe_id'];?>" data-title="<?php echo htmlspecialchars($data['recipe_title'], ENT_QUOTES);?>" title="1-Click Push ke Web Hosting">
												<i class="material-icons" style="color:#009688;">cloud_upload</i>
											</a>
											<?php endif; ?>

											<a href="recipes-send.php?id=<?php echo $data['recipe_id'];?>" title="Kirim Notifikasi">
												<i class="material-icons">notifications_active</i>
											</a>	

											<a href="recipes-detail.php?id=<?php echo $data['recipe_id'];?>" title="Detail">
												<i class="material-icons">launch</i>
											</a>

											<a href="recipes-edit.php?id=<?php echo $data['recipe_id'];?>" title="Edit">
												<i class="material-icons">mode_edit</i>
											</a>

											<a href="recipes-delete.php?id=<?php echo $data['recipe_id'];?>" onclick="return confirm('Are you sure want to delete this Recipe?')" title="Hapus">
												<i class="material-icons">delete</i>
											</a></center>
										</td>
									</tr>
									<?php
									$i++; 
									$count++;
								}
								?>
							</table>

						</form>

						<?php } ?>

						<?php if ($tcount > $postPerPage) { echo pagination($reload, $page, $keyword, $tpages); } ?>
					</div>

				</div>
			</div>
		</div>
	</div>
</section>

<!-- Modal Sync Feedback -->
<div class="modal fade" id="syncModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content corner-radius">
            <div class="modal-header bg-cyan" style="color:white; border-radius: 4px 4px 0 0;">
                <h4 class="modal-title" id="syncModalTitle">Sinkronisasi ke Web Hosting</h4>
            </div>
            <div class="modal-body" id="syncModalBody" style="padding: 20px;">
                <div class="text-center" id="syncModalLoading" style="display:none; padding: 20px;">
                    <div class="preloader pl-size-l">
                        <div class="spinner-layer pl-cyan">
                            <div class="circle-clipper left"><div class="circle"></div></div>
                            <div class="circle-clipper right"><div class="circle"></div></div>
                        </div>
                    </div>
                    <p style="margin-top:15px; font-weight:bold;" id="syncLoadingText">Mengunggah data & foto resep ke web hosting...</p>
                </div>
                <div id="syncModalContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link waves-effect" data-dismiss="modal">TUTUP</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function showSyncModal(title, htmlContent, isLoading, loadingText) {
        document.getElementById('syncModalTitle').innerText = title;
        if (isLoading) {
            document.getElementById('syncModalLoading').style.display = 'block';
            document.getElementById('syncLoadingText').innerText = loadingText || 'Memproses...';
            document.getElementById('syncModalContent').innerHTML = '';
        } else {
            document.getElementById('syncModalLoading').style.display = 'none';
            document.getElementById('syncModalContent').innerHTML = htmlContent;
        }
        $('#syncModal').modal('show');
    }

    function updateSyncCell(recipeId, liveId, dateStr) {
        var cell = document.getElementById('sync-cell-' + recipeId);
        if (cell) {
            cell.innerHTML = '<span class="label bg-green" title="Terakhir disinkron: ' + (dateStr || 'Baru saja') + '">✓ Live #' + liveId + '</span>' +
                '<div style="margin-top: 6px;">' +
                '<button type="button" class="btn btn-xs bg-indigo waves-effect btn-push-single" data-id="' + recipeId + '" title="Sync ulang ke Web Hosting">' +
                '<i class="material-icons" style="font-size:12px;vertical-align:middle;">sync</i> Sync Ulang' +
                '</button>' +
                '</div>';
        }
    }

    // Ping test
    var btnTestSync = document.getElementById('btn-test-sync');
    if (btnTestSync) {
        btnTestSync.addEventListener('click', function() {
            showSyncModal('Tes Koneksi ke Web Hosting', '', true, 'Menghubungi https://airfry.web.id/app/api_sync_recipe.php...');
            fetch('ajax_sync.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=ping'
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    var html = '<div class="alert alert-success" style="margin-bottom:0;">' +
                        '<h4><i class="material-icons" style="vertical-align:middle;">check_circle</i> Terhubung Sukses!</h4>' +
                        '<p>' + (res.message || 'Endpoint hosting aktif dan menerima koneksi.') + '</p>' +
                        '<div style="font-size:12px; margin-top:10px; background:rgba(0,0,0,0.06); padding:8px 12px; border-radius:4px; line-height:1.6;">' +
                        '<div>Waktu server hosting: <strong>' + (res.server_time || '-') + '</strong></div>' +
                        '<div>Folder /upload/ writable: <strong>' + (res.upload_dir_writable ? 'YA' : '<span style="color:#d32f2f;">TIDAK</span>') + '</strong></div>' +
                        (res.video_dir_writable !== undefined ? '<div>Folder /upload/video/ writable: <strong>' + (res.video_dir_writable ? 'YA' : '<span style="color:#d32f2f;">TIDAK</span>') + '</strong></div>' : '') +
                        (res.max_upload_size ? '<div>Batas upload hosting: <strong>upload_max_filesize = ' + res.max_upload_size + ', post_max_size = ' + res.post_max_size + '</strong></div>' : '') +
                        '</div>' +
                        '</div>';
                    showSyncModal('Tes Koneksi Sukses', html, false);
                } else {
                    var html = '<div class="alert alert-danger" style="margin-bottom:0;">' +
                        '<h4><i class="material-icons" style="vertical-align:middle;">error</i> Gagal Terhubung!</h4>' +
                        '<p>' + (res.error || 'Respon hosting tidak sesuai.') + '</p>' +
                        '<p style="font-size:12px; margin-top:8px;">Pastikan file <code>api_sync_recipe.php</code> telah diupload ke <code>/public_html/airfry.web.id/app/</code> di cPanel.</p>' +
                        '</div>';
                    showSyncModal('Tes Koneksi Gagal', html, false);
                }
            })
            .catch(function(err) {
                var html = '<div class="alert alert-danger" style="margin-bottom:0;">' +
                    '<h4><i class="material-icons" style="vertical-align:middle;">error</i> Error Jaringan</h4>' +
                    '<p>' + err.message + '</p>' +
                    '</div>';
                showSyncModal('Koneksi Gagal', html, false);
            });
        });
    }

    // Single push handler (delegated)
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-push-single');
        if (!btn) return;
        e.preventDefault();

        var recipeId = btn.getAttribute('data-id');
        var recipeTitle = btn.getAttribute('data-title') || 'Resep';

        showSyncModal('Menerbitkan ke Web Hosting', '', true, 'Mengunggah "' + recipeTitle + '" beserta foto ke airfry.web.id...');

        var formData = new URLSearchParams();
        formData.append('action', 'push_single');
        formData.append('recipe_id', recipeId);

        fetch('ajax_sync.php', {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                updateSyncCell(recipeId, res.live_recipe_id, 'Baru saja');
                var html = '<div class="alert alert-success" style="margin-bottom:0;">' +
                    '<h4><i class="material-icons" style="vertical-align:middle;">check_circle</i> Resep Berhasil Dipublish!</h4>' +
                    '<p>' + (res.message || 'Resep dan foto sukses tersimpan di hosting live.') + '</p>' +
                    '<p style="margin-top:10px;">ID di hosting live: <span class="badge bg-green">#' + res.live_recipe_id + '</span></p>' +
                    '<div style="margin-top:15px;">' +
                    '<a href="https://airfry.web.id" target="_blank" class="btn btn-sm bg-blue waves-effect" style="color:#fff;"><i class="material-icons" style="font-size:14px;vertical-align:middle;">open_in_new</i> Buka Website Live</a>' +
                    '</div>' +
                    '</div>';
                showSyncModal('Sukses Sinkronisasi', html, false);
            } else {
                var html = '<div class="alert alert-danger" style="margin-bottom:0;">' +
                    '<h4><i class="material-icons" style="vertical-align:middle;">error</i> Gagal Sinkronisasi</h4>' +
                    '<p>' + (res.error || 'Terjadi kesalahan saat push data.') + '</p>' +
                    '</div>';
                showSyncModal('Gagal Sinkronisasi', html, false);
            }
        })
        .catch(function(err) {
            showSyncModal('Error', '<div class="alert alert-danger">Network error: ' + err.message + '</div>', false);
        });
    });

    // Batch push handler
    var btnSyncBatch = document.getElementById('btn-sync-batch');
    if (btnSyncBatch) {
        btnSyncBatch.addEventListener('click', function() {
            var checkedBoxes = document.querySelectorAll('.chkbox:checked');
            if (checkedBoxes.length === 0) {
                alert('Silakan pilih (centang) setidaknya satu resep untuk di-push ke Web Hosting.');
                return;
            }

            var ids = [];
            checkedBoxes.forEach(function(cb) {
                ids.push(cb.value);
            });

            if (!confirm('Anda akan mengirim ' + ids.length + ' resep terpilih ke live web hosting (airfry.web.id). Lanjutkan?')) {
                return;
            }

            showSyncModal('Batch Push ke Web Hosting', '', true, 'Mengunggah ' + ids.length + ' resep dan foto ke web hosting...');

            var formData = new URLSearchParams();
            formData.append('action', 'push_batch');
            formData.append('recipe_ids', ids.join(','));

            fetch('ajax_sync.php', {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    if (res.results && res.results.length) {
                        res.results.forEach(function(item) {
                            if (item.result && item.result.success && item.result.live_recipe_id) {
                                updateSyncCell(item.recipe_id, item.result.live_recipe_id, 'Baru saja');
                            }
                        });
                    }

                    var html = '<div class="alert alert-success" style="margin-bottom:10px;">' +
                        '<h4><i class="material-icons" style="vertical-align:middle;">check_circle</i> Batch Sync Selesai!</h4>' +
                        '<p>' + res.message + '</p>' +
                        '</div>' +
                        '<div style="text-align:center; margin-top:15px;">' +
                        '<a href="https://airfry.web.id" target="_blank" class="btn btn-sm bg-blue waves-effect" style="color:#fff;"><i class="material-icons" style="font-size:14px;vertical-align:middle;">open_in_new</i> Buka Website Live</a>' +
                        '</div>';
                    showSyncModal('Hasil Batch Sync', html, false);
                } else {
                    var html = '<div class="alert alert-danger">' + (res.error || 'Gagal memproses batch sync.') + '</div>';
                    showSyncModal('Gagal Batch Sync', html, false);
                }
            })
            .catch(function(err) {
                showSyncModal('Error', '<div class="alert alert-danger">Network error: ' + err.message + '</div>', false);
            });
        });
    }
});
</script>

<?php include_once('includes/footer.php'); ?>