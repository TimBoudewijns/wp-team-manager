jQuery(document).ready(function($) {
    let currentYear = null, 
        selectedSeason = null, 
        myTeamsCurrentTeamId = null, 
        clubTeamsCurrentTeamId = null, 
        myTeamsCurrentPlayerId = null, 
        clubTeamsCurrentPlayerId = null, 
        selectedDate = null, 
        ratingsData = null, 
        spiderChart = null, 
        isManagerOrOwner = false, 
        activeTab = 'my-teams',
        currentUserId = wptm_ajax.wp_current_user_id; // Huidige user ID uit wptm_ajax

    // Helper function to format dates for display (DD-MM-YYYY)
    function formatDate(dateStr) {
        if (!dateStr) return 'Not set';
        const date = new Date(dateStr);
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        return `${day}-${month}-${year}`;
    }

    // Helper function to format dates for club details (DD MMMM YYYY)
    function formatClubDate(dateStr) {
        if (!dateStr) return 'Unknown';
        const date = new Date(dateStr);
        const options = { day: 'numeric', month: 'long', year: 'numeric' };
        return date.toLocaleDateString('en-GB', options); // Bijv. "15 December 2024"
    }

    // Helper function to show toast notifications
    function showToast(message, type = 'success') {
        const toastId = 'wptm-toast-' + Date.now();
        const bgClass = type === 'success' ? 'bg-success' : 'bg-danger';
        const toastHtml = `
            <div id="${toastId}" class="toast position-fixed top-0 end-0 m-3 ${bgClass} text-white" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header">
                    <strong class="me-auto">${type === 'success' ? 'Success' : 'Error'}</strong>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">${message}</div>
            </div>`;
        $('body').append(toastHtml);
        const toast = new bootstrap.Toast(document.getElementById(toastId), { delay: 3000 });
        toast.show();
        setTimeout(() => $(`#${toastId}`).remove(), 3500);
    }

    // Dynamically populate the season dropdown
    function populateSeasonDropdown() {
        const currentDate = new Date();
        const currentYearNow = currentDate.getFullYear();
        const startYear = currentYearNow - 10; // 10 years in the past
        const endYear = currentYearNow + 5; // 5 years in the future
        let options = '';

        for (let year = startYear; year <= endYear; year++) {
            const season = `${year}-${year + 1}`;
            options += `<option value="${year}">${season}</option>`;
        }

        $('#wptm-season-select').html(options);
        console.log('Season dropdown populated with options from ' + startYear + ' to ' + endYear);
    }

    // Initialize the season dropdown with the saved value
    function initializeSeason() {
        console.log('Initializing season dropdown...');

        // Populate the dropdown with options
        populateSeasonDropdown();

        // Retrieve the saved season from localStorage
        let savedSeason = localStorage.getItem('selectedSeason');
        const currentDate = new Date();
        const defaultYear = currentDate.getFullYear();

        if (savedSeason && savedSeason.match(/^\d{4}-\d{4}$/)) {
            let savedYear = parseInt(savedSeason.split('-')[0]);
            // Check if the saved year is in the dropdown options
            if ($(`#wptm-season-select option[value="${savedYear}"]`).length > 0) {
                console.log('Retrieved saved season from localStorage: ' + savedSeason);
                currentYear = savedYear;
                selectedSeason = savedSeason;
                $('#wptm-season-select').val(savedYear);
            } else {
                console.log('Saved season not in dropdown options, default; currentYear = defaultYear');
                selectedSeason = currentYear + '-' + (currentYear + 1);
                $('#wptm-season-select').val(currentYear);
                localStorage.setItem('selectedSeason', selectedSeason);
                console.log('No valid saved season found, defaulted to: ' + selectedSeason);
            }
        } else {
            // Default to current year if no valid saved season
            currentYear = defaultYear;
            selectedSeason = currentYear + '-' + (currentYear + 1);
            $('#wptm-season-select').val(currentYear);
            localStorage.setItem('selectedSeason', selectedSeason);
            console.log('No valid saved season found, defaulted to: ' + selectedSeason);
        }

        console.log('Initialized season - currentYear: ' + currentYear + ', selectedSeason: ' + selectedSeason);
    }

    function initializeTabs() {
        $('#wptm-team-tabs a').on('click', function(e) { 
            e.preventDefault(); 
            $(this).tab('show'); 
        });

        console.log('Initializing tabs, checking manager teams for season:', selectedSeason);
        $.ajax({
            url: wptm_ajax.ajax_url, 
            type: 'POST', 
            data: { 
                action: 'wptm_get_clubs', 
                nonce: wptm_ajax.nonce
            },
            success: function(response) {
                console.log('wptm_get_clubs response:', response);
                if (response.success) {
                    const clubs = response.data;
                    
                    // Check if the user is a manager or owner of any club
                    const isManagerOrOwner = clubs.some(club => ['manager', 'owner'].includes(club.role));
                    
                    if (isManagerOrOwner) {
                        $('#club-teams-tab').show();
                        $('#trainer-management-tab').show();
                        $('#team-manager-tab').show();
                        console.log('User is a manager or owner, showing all tabs');
                    } else {
                        $('#club-teams-tab').hide();
                        $('#trainer-management-tab').hide();
                        $('#team-manager-tab').hide();
                        console.log('User is not a manager or owner, hiding tabs');
                    }
                    
                    loadTeams(activeTab);
                } else {
                    $('#club-teams-tab').hide();
                    $('#trainer-management-tab').hide();
                    $('#team-manager-tab').hide();
                    console.log('No clubs found or error fetching clubs, hiding tabs');
                    loadTeams(activeTab);
                }
            },
            error: function(xhr, status, error) {
                console.error('Error in wptm_get_clubs:', status, error);
                $('#club-teams-tab').hide();
                $('#trainer-management-tab').hide();
                $('#team-manager-tab').hide();
                loadTeams(activeTab);
            }
        });
    }

    function loadTeams(tab) {
        const action = (tab === 'my-teams') ? 'wptm_get_teams' : 'wptm_get_manager_teams', 
              targetElement = (tab === 'my-teams') ? '#my-teams-list' : '#club-teams-list';

        // Show loading spinner
        $(targetElement).html('<div class="text-center"><span class="loading-spinner"></span> Loading teams...</div>');

        console.log('Fetching teams for tab:', tab, 'season:', selectedSeason);
        $.ajax({
            url: wptm_ajax.ajax_url, 
            type: 'POST', 
            data: { 
                action: action, 
                nonce: wptm_ajax.nonce, 
                season: selectedSeason
            },
            success: function(response) {
                console.log(`Response from ${action}:`, response);
                if (response.success) {
                    renderTeams(response.data, tab);
                } else {
                    console.warn('No teams found in response:', response);
                    renderTeams([], tab);
                }
            },
            error: function(xhr, status, error) {
                console.error(`Error fetching teams for ${tab}:`, status, error);
                $(targetElement).html('<div class="alert alert-danger modern-alert">Failed to load teams. Please try again later.</div>');
            }
        });
    }

    function renderTeams(teams, tab) {
        const targetElement = (tab === 'my-teams') ? '#my-teams-list' : '#club-teams-list';
        let teamsHtml = `<div class="card modern-card"><div class="card-header modern-header"><div class="d-flex align-items-center"><h5 class="card-title modern-title mb-0">Team(s)</h5>${tab === 'my-teams' ? '<button class="modern-btn modern-btn-primary add-team-btn ms-2" title="Add Team"><i class="fa-solid fa-plus"></i></button>' : ''}</div></div><div class="card-body"><ul class="list-group modern-list-group">`;

        if (teams.length > 0) {
            teams.forEach(team => {
                const playerCount = team.player_count || 0;
                const coachName = team.coach || 'Not assigned';
                teamsHtml += `<li class="list-group-item modern-list-item d-flex justify-content-between align-items-center" data-team-id="${team.id}" data-player-count="${playerCount}"><span class="team-info">${team.team_name} (<span class="player-count">${playerCount}</span>) Coach: ${coachName}${tab === 'club-teams' ? ` by ${team.member_name}` : ''}</span></li>`;
            });
        } else {
            teamsHtml += '<li class="list-group-item modern-list-item text-muted">No teams found. Add a team.</li>';
        }

        teamsHtml += '</ul></div></div>';
        $(targetElement).html(teamsHtml);
        console.log(`Rendered teams for ${tab}:`, teams);

        $(targetElement + ' .add-team-btn').click(function() {
            renderAddTeamModal(tab);
        });

        $(targetElement + ' .modern-list-item').click(function() {
            const teamId = $(this).data('team-id');
            if (tab === 'my-teams') myTeamsCurrentTeamId = teamId;
            else clubTeamsCurrentTeamId = teamId;
            $(targetElement + ' .modern-list-item').removeClass('selected-team');
            $(this).addClass('selected-team');
            loadPlayers(tab);
            $(`#${tab}-player-details`).hide();
            $(`#${tab}-spider-chart`).hide();
            $(`#${tab}-player-history`).hide();
        });

        if (teams.length > 0) {
            const currentTeamId = (tab === 'my-teams') ? myTeamsCurrentTeamId : clubTeamsCurrentTeamId;
            if (!currentTeamId) {
                if (tab === 'my-teams') myTeamsCurrentTeamId = teams[0].id;
                else clubTeamsCurrentTeamId = teams[0].id;
                $(targetElement + ' .modern-list-item:first').addClass('selected-team');
            } else {
                $(targetElement + ` .modern-list-item[data-team-id="${currentTeamId}"]`).addClass('selected-team');
            }
            loadPlayers(tab);
        } else {
            if (tab === 'my-teams') myTeamsCurrentTeamId = null;
            else clubTeamsCurrentTeamId = null;
            $(`#${tab}-players-list`).html('<div class="alert alert-info modern-alert">No teams selected or available for this season.</div>');
            $(`#${tab}-player-details`).empty();
            $(`#${tab}-spider-chart`).empty();
            $(`#${tab}-player-history`).empty();
        }
    }

    function renderAddTeamModal(tab) {
        const modalId = 'wptm-add-team-modal';
        let modal = $(`#${modalId}`);
        if (modal.length) modal.remove();
        modal = $(`<div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true"><div class="modal-dialog"><div class="modal-content modern-modal"><div class="modal-header modern-header"><h5 class="modal-title modern-title" id="${modalId}Label">Add New Team</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="mb-3"><label for="wptm-team-name" class="modern-label">Team Name:</label><input type="text" class="form-control modern-input" id="wptm-team-name" placeholder="Enter team name"></div><div class="mb-3"><label for="wptm-team-coach" class="modern-label">Coach Name:</label><input type="text" class="form-control modern-input" id="wptm-team-coach" placeholder="Enter coach name"></div><div class="mb-3"><p class="modern-label text-danger" id="wptm-add-team-error" style="display: none;"></p></div></div><div class="modal-footer"><button type="button" class="modern-btn modern-btn-secondary" data-bs-dismiss="modal">Close</button><button type="button" class="modern-btn modern-btn-primary" id="wptm-add-team-submit">Add Team</button></div></div></div></div>`);
        $('body').append(modal);
        const bootstrapModal = new bootstrap.Modal(modal[0]);
        bootstrapModal.show();

        modal.find('#wptm-add-team-submit').on('click', function() {
            const teamName = $('#wptm-team-name').val().trim();
            const coachName = $('#wptm-team-coach').val().trim();
            const errorElement = $('#wptm-add-team-error');
            errorElement.hide().text('');
            if (!teamName) {
                errorElement.text('Please enter a team name.').show();
                return;
            }
            const data = { 
                action: 'wptm_add_team', 
                nonce: wptm_ajax.nonce, 
                team_name: teamName, 
                coach: coachName, 
                season: selectedSeason
            };
            $.ajax({
                url: wptm_ajax.ajax_url, 
                type: 'POST', 
                data: data,
                success: function(response) {
                    console.log('Add team response:', response);
                    if (response.success && response.data.team_id > 0) {
                        bootstrapModal.hide();
                        loadTeams('my-teams');
                        // If Club Teams tab is visible, reload it as well
                        if (isManagerOrOwner) {
                            loadTeams('club-teams');
                        }
                        showToast('Team added successfully!', 'success');
                    } else {
                        errorElement.text('Error adding team: ' + (response.data.message || 'Unknown error')).show();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error adding team:', status, error);
                    errorElement.text('An error occurred while adding the team.').show();
                }
            });
        });
    }

    function loadPlayers(tab) {
        const currentTeamId = (tab === 'my-teams') ? myTeamsCurrentTeamId : clubTeamsCurrentTeamId;
        if (!currentTeamId) {
            $(`#${tab}-players-list`).html('<div class="alert alert-info modern-alert">No team selected.</div>');
            $(`#${tab}-player-details`).empty();
            $(`#${tab}-spider-chart`).empty();
            $(`#${tab}-player-history`).empty();
            return;
        }
        // Show loading spinner
        $(`#${tab}-players-list`).html('<div class="text-center"><span class="loading-spinner"></span> Loading players...</div>');
        $.ajax({
            url: wptm_ajax.ajax_url, 
            type: 'POST', 
            data: { 
                action: 'wptm_get_players', 
                nonce: wptm_ajax.nonce, 
                team_id: currentTeamId, 
                season: selectedSeason
            },
            success: function(response) {
                console.log('Get players response:', response);
                if (response.success) {
                    renderPlayers(response.data, tab);
                } else {
                    $(`#${tab}-players-list`).html('<div class="alert alert-danger modern-alert">' + (response.data.message || 'Could not load players') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading players:', status, error);
                $(`#${tab}-players-list`).html('<div class="alert alert-danger modern-alert">Failed to load players. Please try again later.</div>');
            }
        });
    }

    function renderPlayers(players, tab) {
        let playersHtml = `<div class="card modern-card"><div class="card-header modern-header"><div class="d-flex align-items-center"><h5 class="card-title modern-title mb-0">Players</h5>${tab === 'my-teams' ? '<button class="modern-btn modern-btn-primary add-player-btn ms-2" title="Add Player"><i class="fa-solid fa-plus"></i></button>' : ''}</div></div><div class="card-body"><ul class="list-group modern-list-group">`;
        if (players.length > 0) {
            players.forEach(player => {
                const firstName = player.first_name || 'Unknown', 
                      lastName = player.last_name || '', 
                      birthDate = player.birth_date || 'Not set';
                const playerHtml = `<li class="list-group-item modern-list-item d-flex justify-content-between align-items-center" data-player-id="${player.id}"><span>${firstName} ${lastName} (Birth Date: ${birthDate})</span><div>${tab === 'my-teams' ? `<button class="modern-btn modern-btn-danger delete-player" title="Delete"><i class="fa-solid fa-trash-can"></i></button><button class="modern-btn modern-btn-primary assess-player" title="Assess"><i class="fa-solid fa-star"></i></button>` : ''}<button class="modern-btn modern-btn-info spider-chart-player" title="Spider Chart"><i class="fa-solid fa-chart-simple"></i></button><button class="modern-btn modern-btn-secondary history-player" title="Team History"><i class="fa-solid fa-clock-rotate-left"></i></button></div></li>`;
                playersHtml += playerHtml;
            });
        } else {
            playersHtml += '<li class="list-group-item modern-list-item text-muted">No players added.</li>';
        }
        playersHtml += '</ul></div></div>';
        $(`#${tab}-players-list`).html(playersHtml);

        const currentTeamId = (tab === 'my-teams') ? myTeamsCurrentTeamId : clubTeamsCurrentTeamId;
        const targetElement = (tab === 'my-teams') ? '#my-teams-list' : '#club-teams-list';
        const playerCount = players.length;
        $(`${targetElement} .modern-list-item[data-team-id="${currentTeamId}"]`).attr('data-player-count', playerCount);
        $(`${targetElement} .modern-list-item[data-team-id="${currentTeamId}"] .player-count`).text(playerCount);

        $(`#${tab}-players-list .delete-player`).on('click', function() {
            const playerId = $(this).closest('.modern-list-item').data('player-id');
            if (confirm('Are you sure you want to remove this player from the team? The player will remain available to add to other teams, and their ratings will be preserved.')) {
                $.ajax({
                    url: wptm_ajax.ajax_url, 
                    type: 'POST', 
                    data: { 
                        action: 'wptm_delete_player_from_team', 
                        nonce: wptm_ajax.nonce, 
                        player_id: playerId, 
                        team_id: myTeamsCurrentTeamId, 
                        season: selectedSeason
                    },
                    success: function(response) {
                        console.log('Delete player response:', response);
                        if (response.success) {
                            loadPlayers(tab);
                            $(`#${tab}-player-details`).empty();
                            $(`#${tab}-spider-chart`).empty();
                            $(`#${tab}-player-history`).empty();
                            // Reload Club Teams to reflect changes
                            if (isManagerOrOwner) {
                                loadTeams('club-teams');
                            }
                            showToast('Player removed successfully!', 'success');
                        } else {
                            showToast(response.data.message || 'Could not remove player.', 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error deleting player:', status, error);
                        showToast('An error occurred while removing the player.', 'error');
                    }
                });
            }
        });

        $(`#${tab}-players-list .assess-player`).on('click', function() {
            const playerId = $(this).closest('.modern-list-item').data('player-id');
            if (tab === 'my-teams') myTeamsCurrentPlayerId = playerId;
            $(`#${tab}-players-list .modern-list-item`).removeClass('selected-player');
            $(this).closest('.modern-list-item').addClass('selected-player');
            renderAssessmentForm(tab);
        });

        $(`#${tab}-players-list .spider-chart-player`).on('click', function() {
            const playerId = $(this).closest('.modern-list-item').data('player-id');
            if (tab === 'my-teams') myTeamsCurrentPlayerId = playerId;
            else clubTeamsCurrentPlayerId = playerId;
            $(`#${tab}-players-list .modern-list-item`).removeClass('selected-player');
            $(this).closest('.modern-list-item').addClass('selected-player');
            renderSpiderChart(tab);
        });

        $(`#${tab}-players-list .history-player`).on('click', function() {
            const playerId = $(this).closest('.modern-list-item').data('player-id');
            if (tab === 'my-teams') myTeamsCurrentPlayerId = playerId;
            else clubTeamsCurrentPlayerId = playerId;
            $(`#${tab}-players-list .modern-list-item`).removeClass('selected-player');
            $(this).closest('.modern-list-item').addClass('selected-player');
            renderPlayerHistory(tab);
        });

        if (tab === 'my-teams') {
            $(`#${tab}-players-list .add-player-btn`).on('click', function() {
                $.ajax({
                    url: wptm_ajax.ajax_url, 
                    method: 'POST', 
                    data: { 
                        action: 'wptm_get_players', 
                        nonce: wptm_ajax.nonce, 
                        all_players: true 
                    },
                    success: function(response) {
                        console.log('Get all players response:', response);
                        if (response.success) renderAddPlayerModal(response.data, tab);
                        else showToast('Error loading players: ' + response.data.message, 'error');
                    },
                    error: function(xhr, status, error) { 
                        console.error('Error loading all players:', status, error);
                        showToast('An error occurred while loading players.', 'error'); 
                    }
                });
            });
        }
    }

    function renderAddPlayerModal(players, tab) {
        const modalId = 'wptm-add-player-modal';
        let modal = $(`#${modalId}`);
        if (modal.length) modal.remove();
        modal = $(`<div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true"><div class="modal-dialog"><div class="modal-content modern-modal"><div class="modal-header modern-header"><h5 class="modal-title modern-title" id="${modalId}Label">Add Player to Team</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="mb-3"><label class="modern-label">Choose an existing player:</label><select class="form-select modern-select" id="wptm-player-select"><option value="">-- Select a player --</option>${players.map(player => `<option value="${player.id}">${player.first_name} ${player.last_name} (Birth Date: ${player.birth_date || 'Not set'})</option>`).join('')}</select></div><div class="mb-3 existing-player-details" style="display: none;"><label for="wptm-player-existing-position" class="modern-label">Position for this team:</label><input type="text" class="form-control modern-input mb-2" id="wptm-player-existing-position" placeholder="Position (e.g., Forward, Midfielder)"><label for="wptm-player-existing-number" class="modern-label">Player Number for this team:</label><input type="text" class="form-control modern-input mb-2" id="wptm-player-existing-number" placeholder="Player Number (e.g., 10)"></div><div class="mb-3"><p class="modern-label">Or add a new player:</p><input type="text" class="form-control modern-input mb-2" id="wptm-player-first-name" placeholder="First Name"><input type="text" class="form-control modern-input mb-2" id="wptm-player-last-name" placeholder="Last Name"><input type="date" class="form-control modern-input mb-2" id="wptm-player-birth-date" placeholder="Birth Date"><input type="text" class="form-control modern-input mb-2" id="wptm-player-position" placeholder="Position for this team (e.g., Forward, Midfielder)"><input type="text" class="form-control modern-input mb-2" id="wptm-player-number" placeholder="Player Number for this team (e.g., 10)"></div><div class="mb-3"><p class="modern-label text-danger" id="wptm-add-player-error" style="display: none;"></p></div></div><div class="modal-footer"><button type="button" class="modern-btn modern-btn-secondary" data-bs-dismiss="modal">Close</button><button type="button" class="modern-btn modern-btn-primary" id="wptm-add-player-submit">Add Player</button></div></div></div></div>`);
        $('body').append(modal);
        const bootstrapModal = new bootstrap.Modal(modal[0]);
        bootstrapModal.show();

        modal.find('#wptm-player-select').on('change', function() {
            const playerId = $(this).val();
            if (playerId) {
                modal.find('.existing-player-details').show();
            } else {
                modal.find('.existing-player-details').hide();
                modal.find('#wptm-player-existing-position').val('');
                modal.find('#wptm-player-existing-number').val('');
            }
        });

        modal.find('#wptm-add-player-submit').on('click', function() {
            const playerId = $('#wptm-player-select').val(), 
                  firstName = $('#wptm-player-first-name').val().trim(), 
                  lastName = $('#wptm-player-last-name').val().trim(), 
                  birthDate = $('#wptm-player-birth-date').val(), 
                  position = playerId ? $('#wptm-player-existing-position').val().trim() : $('#wptm-player-position').val().trim(), 
                  playerNumber = playerId ? $('#wptm-player-existing-number').val().trim() : $('#wptm-player-number').val().trim(), 
                  errorElement = $('#wptm-add-player-error');
            errorElement.hide().text('');
            if (!playerId && (!firstName || !lastName)) {
                errorElement.text('Choose an existing player or fill in both the first and last name.').show();
                return;
            }
            if (!position) {
                errorElement.text('Please enter a position for this team.').show();
                return;
            }
            if (!playerNumber) {
                errorElement.text('Please enter a player number for this team.').show();
                return;
            }
            const data = { 
                action: 'wptm_add_player_to_team', 
                nonce: wptm_ajax.nonce, 
                team_id: myTeamsCurrentTeamId, 
                season: selectedSeason,
                player_id: playerId || null, 
                first_name: firstName, 
                last_name: lastName, 
                birth_date: birthDate || null, 
                position: position || null, 
                player_number: playerNumber || null 
            };
            $.ajax({
                url: wptm_ajax.ajax_url, 
                type: 'POST', 
                data: data,
                success: function(response) {
                    console.log('Add player response:', response);
                    if (response.success) {
                        bootstrapModal.hide();
                        loadPlayers(tab);
                        // Reload Club Teams to reflect changes
                        if (isManagerOrOwner) {
                            loadTeams('club-teams');
                        }
                        showToast('Player added successfully!', 'success');
                    } else {
                        errorElement.text('Error adding player: ' + response.data.message).show();
                    }
                },
                error: function(xhr, status, error) { 
                    console.error('Error adding player:', status, error);
                    errorElement.text('An error occurred while adding the player.').show(); 
                }
            });
        });
    }

    function renderAssessmentForm(tab) {
        ratingsData = null;
        const skills = ['technique', 'speed', 'endurance', 'intelligence', 'passing', 'defense', 'attack', 'teamwork', 'agility', 'strength'];
        const today = new Date().toISOString().split('T')[0];
        selectedDate = today;
        let assessHtml = `<div class="card modern-card"><div class="card-header modern-header"><h3 class="card-title modern-title mb-0">Assess Player</h3></div><div class="card-body"><div class="mb-4"><label for="assess-date-${tab}" class="form-label modern-label">Select Date:</label><input type="date" id="assess-date-${tab}" class="form-control modern-input w-auto" value="${today}"></div><div id="ratings-form-${tab}" style="display: block;"><div class="row">${skills.map(skill => `<div class="col-12 col-md-6 mb-4"><label class="form-label modern-label text-capitalize">${skill}:</label><div class="rating-circles modern-rating-circles" data-skill="${skill}">${Array.from({length: 10}, (_, i) => i + 1).map(i => `<span class="rating-circle modern-rating-circle" data-value="${i}">${i}</span>`).join('')}</div></div>`).join('')}</div><button id="save-ratings-${tab}" class="modern-btn modern-btn-primary mt-3" data-player-id="${myTeamsCurrentPlayerId}">Save Assessment</button></div></div></div><div class="card modern-card mt-3"><div class="card-header modern-header"><h4 class="card-title modern-title mb-0">Yearly Assessment Overview</h4></div><div class="card-body"><div class="ratings-table-container d-flex"><div id="ratings-skills-column-${tab}" class="ratings-skills-column"><div class="ratings-skills-header">Skill</div></div><div class="ratings-scrollable-table"><table id="player-ratings-table-${tab}" class="table modern-table"><thead class="modern-table-head"><tr></tr></thead><tbody></tbody></table></div></div></div></div>`;
        $(`#${tab}-player-details`).html(assessHtml).show();
        $(`#${tab}-spider-chart`).hide();
        $(`#${tab}-player-history`).hide();
        loadPlayerRatings(tab);

        $(`#assess-date-${tab}`).on('change', function() {
            selectedDate = $(this).val();
            console.log(`Date changed to: ${selectedDate}`);
            if (selectedDate) {
                $(`#ratings-form-${tab}`).show();
                $(`.rating-circle`).removeClass('selected');
                let existingRating = ratingsData && ratingsData.find(rating => rating.rating_date === selectedDate);
                if (existingRating) {
                    skills.forEach(skill => {
                        let value = parseInt(existingRating[skill]);
                        if (value > 0) $(`#${tab}-player-details .rating-circles[data-skill="${skill}"] .rating-circle[data-value="${value}"]`).addClass('selected');
                    });
                }
            } else {
                $(`#ratings-form-${tab}`).hide();
            }
        });

        $(`#${tab}-player-details .rating-circle`).click(function() {
            let skill = $(this).parent().data('skill'), 
                value = $(this).data('value');
            $(`#${tab}-player-details .rating-circles[data-skill="${skill}"] .rating-circle`).removeClass('selected');
            $(this).addClass('selected');
        });

        $(`#save-ratings-${tab}`).click(function() {
            const dateInput = $(`#assess-date-${tab}`).val();
            console.log(`Save clicked - Date input value: ${dateInput}, selectedDate: ${selectedDate}`);
            if (!dateInput) {
                $(`#ratings-form-${tab}`).prepend('<div class="alert alert-warning modern-alert alert-dismissible fade show" role="alert">Select a date before saving the assessment. <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>');
                return;
            }
            let ratings = {};
            skills.forEach(skill => {
                ratings[skill] = $(`#${tab}-player-details .rating-circles[data-skill="${skill}"] .rating-circle.selected`).data('value') || 0;
            });
            let allZero = Object.values(ratings).every(value => value === 0);
            if (allZero) {
                $(`#ratings-form-${tab}`).prepend('<div class="alert alert-warning modern-alert alert-dismissible fade show" role="alert">Provide at least one rating. <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>');
                return;
            }
            console.log('Saving assessment with season:', selectedSeason);
            $.ajax({
                url: wptm_ajax.ajax_url, 
                type: 'POST', 
                data: { 
                    action: 'wptm_save_ratings', 
                    nonce: wptm_ajax.nonce, 
                    player_id: myTeamsCurrentPlayerId, 
                    team_id: myTeamsCurrentTeamId, 
                    rating_date: dateInput, 
                    season: selectedSeason,
                    ratings: ratings 
                },
                success: function(response) {
                    console.log('Save ratings response:', response);
                    if (response.success) {
                        $(`#ratings-form-${tab}`).prepend('<div class="alert alert-success modern-alert alert-dismissible fade show" role="alert">Assessment successfully saved! <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>');
                        loadPlayerRatings(tab);
                        // Reload Club Teams to reflect changes
                        if (isManagerOrOwner) {
                            loadTeams('club-teams');
                        }
                        showToast('Assessment saved successfully!', 'success');
                    } else {
                        $(`#ratings-form-${tab}`).prepend('<div class="alert alert-danger modern-alert alert-dismissible fade show" role="alert">' + (response.data.message || 'Error saving assessment') + ' <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error saving ratings:', status, error);
                    $(`#ratings-form-${tab}`).prepend('<div class="alert alert-danger modern-alert alert-dismissible fade show" role="alert">Could not save assessment. <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>');
                }
            });
        });
    }

    function loadPlayerRatings(tab) {
        const currentPlayerId = (tab === 'my-teams') ? myTeamsCurrentPlayerId : clubTeamsCurrentPlayerId;
        const currentTeamId = (tab === 'my-teams') ? myTeamsCurrentTeamId : clubTeamsCurrentTeamId;
        if (!currentPlayerId || !currentTeamId) return;
        $.ajax({
            url: wptm_ajax.ajax_url, 
            type: 'POST', 
            data: { 
                action: 'wptm_get_player_ratings', 
                nonce: wptm_ajax.nonce, 
                player_id: currentPlayerId, 
                team_id: currentTeamId, 
                season: selectedSeason
            },
            success: function(response) {
                console.log('Get player ratings response:', response);
                if (response.success) {
                    ratingsData = response.data;
                    const dates = [...new Set(ratingsData.map(rating => rating.rating_date))].sort();
                    const skills = ['technique', 'speed', 'endurance', 'intelligence', 'passing', 'defense', 'attack', 'teamwork', 'agility', 'strength'];
                    let skillsColumnHtml = '<div class="ratings-skills-header">Skill</div>';
                    skills.forEach(skill => skillsColumnHtml += `<div class="ratings-skills-cell text-capitalize">${skill}</div>`);
                    $(`#ratings-skills-column-${tab}`).html(skillsColumnHtml);
                    let thead = '<tr>' + dates.map(date => `<th>${formatDate(date)}</th>`).join('') + '</tr>';
                    $(`#player-ratings-table-${tab} thead`).html(thead);
                    let tbody = '';
                    skills.forEach(skill => {
                        tbody += '<tr>' + dates.map(date => {
                            let rating = ratingsData.find(r => r.rating_date === date);
                            return `<td>${rating ? rating[skill] : '-'}</td>`;
                        }).join('') + '</tr>';
                    });
                    $(`#player-ratings-table-${tab} tbody`).html(tbody);
                    syncRowHeights(tab);
                } else {
                    $(`#${tab}-player-details`).html('<div class="alert alert-danger modern-alert">' + (response.data.message || 'Could not load ratings') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading player ratings:', status, error);
                $(`#${tab}-player-details`).html('<div class="alert alert-danger modern-alert">Failed to load ratings.</div>');
            }
        });
    }

    function syncRowHeights(tab) {
        const skillCells = $(`#ratings-skills-column-${tab} .ratings-skills-cell`), 
              tableRows = $(`#player-ratings-table-${tab} tbody tr`), 
              headerCell = $(`#ratings-skills-column-${tab} .ratings-skills-header`), 
              tableHeaderRow = $(`#player-ratings-table-${tab} thead tr`);
        const headerHeight = $(tableHeaderRow).outerHeight();
        $(headerCell).css('height', headerHeight + 'px');
        skillCells.each(function(index) {
            const rowHeight = $(tableRows[index]).outerHeight();
            $(this).css('height', rowHeight + 'px');
        });
    }

    function renderSpiderChart(tab) {
        const currentPlayerId = (tab === 'my-teams') ? myTeamsCurrentPlayerId : clubTeamsCurrentPlayerId;
        const currentTeamId = (tab === 'my-teams') ? myTeamsCurrentTeamId : clubTeamsCurrentTeamId;
        $.ajax({
            url: wptm_ajax.ajax_url, 
            type: 'POST', 
            data: { 
                action: 'wptm_get_spider_chart', 
                nonce: wptm_ajax.nonce, 
                player_id: currentPlayerId, 
                team_id: currentTeamId,
                season: selectedSeason
            },
            success: function(response) {
                console.log('Get spider chart response:', response);
                if (response.success) {
                    let data = response.data.ratings, 
                        player = response.data.player, 
                        coachAdvice = response.data.coach_advice || 'No advice available.';
                    let chartData = [
                        parseFloat(data.technique) || 0, 
                        parseFloat(data.speed) || 0, 
                        parseFloat(data.endurance) || 0, 
                        parseFloat(data.intelligence) || 0, 
                        parseFloat(data.passing) || 0, 
                        parseFloat(data.defense) || 0, 
                        parseFloat(data.attack) || 0, 
                        parseFloat(data.teamwork) || 0, 
                        parseFloat(data.agility) || 0, 
                        parseFloat(data.strength) || 0
                    ];
                    let displayData = chartData.map(value => value.toFixed(1));
                    let spiderHtml = `
                        <div class="card professional-card">
                            <div class="card-header modern-header">
                                <h3 class="card-title modern-title mb-0">Player Performance (${player.first_name} ${player.last_name})</h3>
                            </div>
                            <div class="card-body">
                                <div class="row professional-chart-row">
                                    <div class="col-12 col-md-6 mb-4">
                                        <ul class="list-group professional-list-group">
                                            <li class="list-group-item professional-list-item">Technique: ${displayData[0]}</li>
                                            <li class="list-group-item professional-list-item">Speed: ${displayData[1]}</li>
                                            <li class="list-group-item professional-list-item">Endurance: ${displayData[2]}</li>
                                            <li class="list-group-item professional-list-item">Intelligence: ${displayData[3]}</li>
                                            <li class="list-group-item professional-list-item">Passing: ${displayData[4]}</li>
                                            <li class="list-group-item professional-list-item">Defense: ${displayData[5]}</li>
                                            <li class="list-group-item professional-list-item">Attack: ${displayData[6]}</li>
                                            <li class="list-group-item professional-list-item">Teamwork: ${displayData[7]}</li>
                                            <li class="list-group-item professional-list-item">Agility: ${displayData[8]}</li>
                                            <li class="list-group-item professional-list-item">Strength: ${displayData[9]}</li>
                                        </ul>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div class="professional-chart-container">
                                            <canvas id="${tab}-spider-chart-canvas"></canvas>
                                        </div>
                                    </div>
                                </div>
                                <div class="professional-info-section mt-4">
                                    <h4 class="professional-subtitle">Player Information</h4>
                                    <div class="professional-info-container">
                                        <div class="professional-info-details">
                                            <div class="professional-info-item"><span class="professional-info-label">Name:</span> ${player.first_name} ${player.last_name}</div>
                                            <div class="professional-info-item"><span class="professional-info-label">Birth Date:</span> ${player.birth_date || 'Not set'}</div>
                                            <div class="professional-info-item"><span class="professional-info-label">Position:</span> ${player.position || 'Not set'}</div>
                                            <div class="professional-info-item"><span class="professional-info-label">Player Number:</span> ${player.player_number || 'Not set'}</div>
                                        </div>
                                        <div class="professional-info-advice">
                                            <div class="professional-info-item"><span class="professional-info-label">Coach Advice:</span> ${coachAdvice}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>`;
                    $(`#${tab}-spider-chart`).html(spiderHtml).show();
                    $(`#${tab}-player-details`).hide();
                    $(`#${tab}-player-history`).hide();
                    let ctx = document.getElementById(`${tab}-spider-chart-canvas`).getContext('2d');
                    if (spiderChart) spiderChart.destroy();
                    spiderChart = new Chart(ctx, {
                        type: 'radar',
                        data: {
                            labels: ['Technique', 'Speed', 'Endurance', 'Intelligence', 'Passing', 'Defense', 'Attack', 'Teamwork', 'Agility', 'Strength'],
                            datasets: [{
                                label: 'Average Skills',
                                data: chartData,
                                fill: true,
                                backgroundColor: 'rgba(230, 126, 34, 0.2)',
                                borderColor: '#E67E22',
                                pointBackgroundColor: '#E67E22',
                                pointBorderColor: '#fff',
                                pointHoverBackgroundColor: '#fff',
                                pointHoverBorderColor: '#E67E22'
                            }]
                        },
                        options: {
                            scales: {
                                r: {
                                    min: 0, 
                                    max: 10,
                                    ticks: { 
                                        font: { 
                                            family: '"Roboto", sans-serif',
                                            weight: '500'
                                        },
                                        color: '#4A5568'
                                    },
                                    grid: { 
                                        color: 'rgba(0, 0, 0, 0.1)' 
                                    },
                                    pointLabels: {
                                        font: {
                                            family: '"Roboto", sans-serif',
                                            size: 14,
                                            weight: '600'
                                        },
                                        color: '#2D3748'
                                    }
                                }
                            },
                            plugins: {
                                legend: { 
                                    labels: { 
                                        font: { 
                                            family: '"Roboto", sans-serif',
                                            size: 14,
                                            weight: '500'
                                        },
                                        color: '#2D3748'
                                    } 
                                }
                            },
                            responsive: true, 
                            maintainAspectRatio: false,
                            animation: {
                                duration: 1000,
                                easing: 'easeInOutQuad'
                            }
                        }
                    });

                    setTimeout(() => {
                        const listGroup = $(`#${tab}-spider-chart .professional-list-group`);
                        const chartContainer = $(`#${tab}-spider-chart .professional-chart-container`);
                        if (listGroup.length && chartContainer.length) {
                            const listHeight = listGroup.outerHeight();
                            chartContainer.css('height', `${listHeight}px`);
                        }
                    }, 100);
                } else {
                    $(`#${tab}-spider-chart`).html('<div class="alert alert-danger modern-alert">' + (response.data.message || 'Could not load performance') + '</div>').show();
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading spider chart:', status, error);
                $(`#${tab}-spider-chart`).html('<div class="alert alert-danger modern-alert">Failed to load performance.</div>').show();
            }
        });
    }

    function renderPlayerHistory(tab) {
        const currentPlayerId = (tab === 'my-teams') ? myTeamsCurrentPlayerId : clubTeamsCurrentPlayerId;
        $.ajax({
            url: wptm_ajax.ajax_url, 
            type: 'POST', 
            data: { 
                action: 'wptm_get_player_history', 
                nonce: wptm_ajax.nonce, 
                player_id: currentPlayerId 
            },
            success: function(response) {
                console.log('Get player history response:', response);
                if (response.success) {
                    let historyHtml = `<div class="card modern-card"><div class="card-header modern-header"><h3 class="card-title modern-title mb-0">Team History</h3></div><div class="card-body"><ul class="modern-timeline">`;
                    if (response.data.length === 0) {
                        historyHtml += '<li class="modern-timeline-item"><div class="timeline-content">No team history found for this player.</div></li>';
                    } else {
                        response.data.forEach(entry => {
                            historyHtml += `<li class="modern-timeline-item"><div class="timeline-year">${entry.season}</div><div class="timeline-content">${entry.team_name} (${entry.position || 'No position'})</div></li>`;
                        });
                    }
                    historyHtml += '</ul></div></div>';
                    $(`#${tab}-player-history`).html(historyHtml).show();
                    $(`#${tab}-player-details`).hide();
                    $(`#${tab}-spider-chart`).hide();
                } else {
                    console.error('Error fetching player history:', response.data);
                    $(`#${tab}-player-history`).html('<div class="alert alert-danger modern-alert">' + (response.data.message || 'Could not load history') + '</div>').show();
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading player history:', status, error);
                $(`#${tab}-player-history`).html('<div class="alert alert-danger modern-alert">Failed to load history.</div>').show();
            }
        });
    }

    // Nieuwe functies voor Trainer Management

    function loadTrainerManagement() {
        $('#trainer-management-content').html('<div class="text-center"><span class="loading-spinner"></span> Loading clubs...</div>');
        $.ajax({
            url: wptm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wptm_get_clubs',
                nonce: wptm_ajax.nonce
            },
            success: function(response) {
                console.log('Load clubs response:', response);
                if (response.success) {
                    renderClubs(response.data);
                } else {
                    $('#trainer-management-content').html('<div class="alert alert-danger modern-alert">Failed to load clubs.</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading clubs:', status, error);
                $('#trainer-management-content').html('<div class="alert alert-danger modern-alert">Failed to load clubs.</div>');
            }
        });
    }

    function renderClubs(clubs) {
        let clubsHtml = `<div class="card modern-card"><div class="card-header modern-header"><h5 class="card-title modern-title mb-0">Clubs</h5></div><div class="card-body"><ul class="list-group modern-list-group">`;
        if (clubs.length > 0) {
            clubs.forEach(club => {
                if (['manager', 'owner'].includes(club.role)) {
                    clubsHtml += `<li class="list-group-item modern-list-item d-flex justify-content-between align-items-center" data-club-id="${club.id}"><span class="club-info">${club.name} (Role: ${club.role})</span></li>`;
                }
            });
        } else {
            clubsHtml += '<li class="list-group-item modern-list-item text-muted">No clubs found where you are a manager or owner.</li>';
        }
        clubsHtml += '</ul></div></div>';
        $('#trainer-management-content').html(clubsHtml);

        $('#trainer-management-content .modern-list-item').click(function() {
            const clubId = $(this).data('club-id');
            $('#trainer-management-content .modern-list-item').removeClass('selected-club');
            $(this).addClass('selected-club');
            loadClubDetails(clubId);
        });

        if (clubs.length > 0) {
            const firstClub = clubs.find(club => ['manager', 'owner'].includes(club.role));
            if (firstClub) {
                $(`#trainer-management-content .modern-list-item[data-club-id="${firstClub.id}"]`).addClass('selected-club');
                loadClubDetails(firstClub.id);
            }
        }
    }

    function loadClubDetails(clubId) {
        $('#trainer-management-content').append('<div class="text-center"><span class="loading-spinner"></span> Loading club details...</div>');
        $.ajax({
            url: wptm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wptm_get_club_details',
                nonce: wptm_ajax.nonce,
                club_id: clubId
            },
            success: function(response) {
                console.log('Load club details response:', response);
                $('#trainer-management-content .loading-spinner').parent().remove();
                if (response.success) {
                    renderClubDetails(response.data);
                } else {
                    showToast(response.data.message || 'Could not load club details.', 'error');
                    $('#trainer-management-content').append('<div class="alert alert-danger modern-alert mt-3">' + (response.data.message || 'Could not load club details') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading club details:', status, error, xhr.responseText);
                $('#trainer-management-content .loading-spinner').parent().remove();
                showToast('Failed to load club details. Please try again.', 'error');
                $('#trainer-management-content').append('<div class="alert alert-danger modern-alert mt-3">Failed to load club details: ' + (xhr.responseText || 'Unknown error') + '</div>');
            }
        });
    }

    function renderClubDetails(data) {
        console.log('Rendering club details with data:', data);
        const club = {
            id: data.id,
            name: data.name,
            user_role: data.role,
            user_display_role: data.display_role || getDisplayRoleFromRole(data.role),
            plan: data.plan || 'Unknown Plan',
            seat_count: data.seat_count || 'Unknown',
            member_count: data.member_count || 0,
            free_seats: data.free_seats || 'Unknown',
            created_on: data.created_on || 'Unknown',
            member_since: data.member_since || 'Unknown'
        };
        const trainers = data.trainers || [];
        const invitations = data.invitations || [];
        const isOwner = club.user_role === 'owner';
        const currentUserId = wptm_ajax.wp_current_user_id;

        // Hulpfunctie om een rolnaam te vertalen als display_role niet beschikbaar is
        function getDisplayRoleFromRole(role) {
            switch(role) {
                case 'owner': return 'Owner';
                case 'manager': return 'Manager';
                case 'member': return 'Trainer';
                case 'sports_coordinator': return 'Sports Coordinator';
                default: return role ? role.charAt(0).toUpperCase() + role.slice(1) : 'Unknown';
            }
        }

        // Club Details Section with Edit Option (for owners only)
        let clubDetailsHtml = `
            <div class="card modern-card mt-3">
                <div class="card-header modern-header">
                    <div class="d-flex align-items-center">
                        <h5 class="card-title modern-title mb-0">Club Details</h5>
                        ${isOwner ? '<button class="modern-btn modern-btn-primary edit-club-name-btn ms-2" title="Edit Club Name"><i class="fa-solid fa-pen"></i></button>' : ''}
                    </div>
                </div>
                <div class="card-body">
                    <div id="club-name-display">
                        <p><strong>Name:</strong> ${club.name}</p>
                        <p><strong>Your Role:</strong> ${club.user_display_role}</p>
                        <p><strong>Plan:</strong> ${club.plan}</p>
                        <p><strong>Total Seats:</strong> ${club.seat_count}</p>
                        <p><strong>Current Members:</strong> ${club.member_count}</p>
                        <p><strong>Free Seats:</strong> ${club.free_seats}</p>
                        <p><strong>Created On:</strong> ${formatClubDate(club.created_on)}</p>
                        <p><strong>Member Since:</strong> ${formatClubDate(club.member_since)}</p>
                    </div>
                    <div id="club-name-edit" style="display: none;">
                        <div class="mb-3">
                            <label for="club-name-input" class="modern-label">Club Name:</label>
                            <input type="text" class="form-control modern-input" id="club-name-input" value="${club.name}">
                        </div>
                        <button class="modern-btn modern-btn-primary save-club-name-btn">Save</button>
                        <button class="modern-btn modern-btn-secondary cancel-edit-club-name-btn ms-2">Cancel</button>
                    </div>
                </div>
            </div>`;

        // Trainers Section
        clubDetailsHtml += `
            <div class="card modern-card mt-3">
                <div class="card-header modern-header">
                    <div class="d-flex align-items-center">
                        <h5 class="card-title modern-title mb-0">Team Members</h5>
                        <button class="modern-btn modern-btn-primary invite-trainer-btn ms-2" title="Invite Member"><i class="fa-solid fa-plus"></i></button>
                    </div>
                </div>
                <div class="card-body">
                    <ul class="list-group modern-list-group">`;
        if (trainers.length > 0) {
            trainers.forEach(trainer => {
                // Gebruik de display_role als beschikbaar, anders vertaal de rol
                const displayRole = trainer.display_role || getDisplayRoleFromRole(trainer.role);
                const canRemove = trainer.id !== currentUserId && trainer.role !== 'owner' && (isOwner || (club.user_role === 'manager' && trainer.role === 'member'));
                const canChangeRole = isOwner && trainer.id !== currentUserId && trainer.role !== 'owner';
                clubDetailsHtml += `
                    <li class="list-group-item modern-list-item d-flex justify-content-between align-items-center" data-trainer-id="${trainer.id}">
                        <span>${trainer.name} (${trainer.email}) - Role: ${displayRole}</span>
                        <div>
                            ${canChangeRole ? `
                                <select class="form-select modern-select change-trainer-role ms-2" style="display: inline-block; width: auto;">
                                    <option value="member" ${trainer.role === 'member' && !trainer.is_coordinator ? 'selected' : ''}>Trainer</option>
                                    <option value="sports_coordinator" ${trainer.role === 'sports_coordinator' || trainer.is_coordinator ? 'selected' : ''}>Sports Coordinator</option>
                                    <option value="manager" ${trainer.role === 'manager' ? 'selected' : ''}>Manager</option>
                                </select>` : ''}
                            ${canRemove ? `<button class="modern-btn modern-btn-danger remove-trainer ms-2" title="Remove"><i class="fa-solid fa-trash-can"></i></button>` : ''}
                        </div>
                    </li>`;
            });
        } else {
            clubDetailsHtml += '<li class="list-group-item modern-list-item text-muted">No team members added.</li>';
        }
        clubDetailsHtml += `</ul></div></div>`;

        // Invitations Section
        clubDetailsHtml += `
            <div class="card modern-card mt-3">
                <div class="card-header modern-header">
                    <h5 class="card-title modern-title mb-0">Pending Invitations</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group modern-list-group">`;
        if (invitations.length > 0) {
            invitations.forEach(invitation => {
                // Gebruik de display_role als beschikbaar, anders vertaal de rol
                const displayRole = invitation.display_role || getDisplayRoleFromRole(invitation.role);
                clubDetailsHtml += `
                    <li class="list-group-item modern-list-item d-flex justify-content-between align-items-center" data-invitation-id="${invitation.id}">
                        <span>${invitation.email} - Role: ${displayRole}</span>
                        <button class="modern-btn modern-btn-danger cancel-invitation" title="Cancel Invitation"><i class="fa-solid fa-times"></i></button>
                    </li>`;
            });
        } else {
            clubDetailsHtml += '<li class="list-group-item modern-list-item text-muted">No pending invitations.</li>';
        }
        clubDetailsHtml += `</ul></div></div>`;

        // Vervang inhoud in plaats van toevoegen
        jQuery('#trainer-management-content').html(clubDetailsHtml);
        console.log('Club details rendered for club ID:', club.id);

        // Event Listeners for Club Name Editing
        jQuery('.edit-club-name-btn').on('click', function() {
            jQuery('#club-name-display').hide();
            jQuery('#club-name-edit').show();
        });

        jQuery('.cancel-edit-club-name-btn').on('click', function() {
            jQuery('#club-name-edit').hide();
            jQuery('#club-name-display').show();
        });

        jQuery('.save-club-name-btn').on('click', function() {
            const newName = jQuery('#club-name-input').val().trim();
            if (!newName) {
                showToast('Club name cannot be empty.', 'error');
                return;
            }
            jQuery.ajax({
                url: wptm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wptm_update_club_name',
                    nonce: wptm_ajax.nonce,
                    club_id: club.id,
                    name: newName
                },
                success: function(response) {
                    console.log('Update club name response:', response);
                    if (response.success) {
                        jQuery('#club-name-display').html(`
                            <p><strong>Name:</strong> ${newName}</p>
                            <p><strong>Your Role:</strong> ${club.user_display_role}</p>
                            <p><strong>Plan:</strong> ${club.plan}</p>
                            <p><strong>Total Seats:</strong> ${club.seat_count}</p>
                            <p><strong>Current Members:</strong> ${club.member_count}</p>
                            <p><strong>Free Seats:</strong> ${club.free_seats}</p>
                            <p><strong>Created On:</strong> ${formatClubDate(club.created_on)}</p>
                            <p><strong>Member Since:</strong> ${formatClubDate(club.member_since)}</p>
                        `);
                        jQuery(`#trainer-management-content .modern-list-item[data-club-id="${club.id}"] .club-info`).text(`${newName} (Role: ${club.user_display_role})`);
                        jQuery('#club-name-edit').hide();
                        jQuery('#club-name-display').show();
                        showToast('Club name updated successfully!', 'success');
                    } else {
                        showToast(response.data.message || 'Error updating club name.', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error updating club name:', status, error);
                    showToast('An error occurred while updating the club name.', 'error');
                }
            });
        });

        // Invite Trainer Button
        jQuery('.invite-trainer-btn').on('click', function() {
            renderInviteTrainerModal(club.id);
        });

        // Remove Trainer Button
        jQuery('.remove-trainer').on('click', function() {
            if (confirm('Are you sure you want to remove this member from the club?')) {
                const trainerId = jQuery(this).closest('.modern-list-item').data('trainer-id');
                jQuery.ajax({
                    url: wptm_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wptm_remove_trainer',
                        nonce: wptm_ajax.nonce,
                        club_id: club.id,
                        trainer_id: trainerId
                    },
                    success: function(response) {
                        console.log('Remove trainer response:', response);
                        if (response.success) {
                            loadClubDetails(club.id);
                            showToast('Member removed successfully!', 'success');
                        } else {
                            showToast(response.data.message || 'Error removing member.', 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error removing trainer:', status, error);
                        showToast('An error occurred while removing the member.', 'error');
                    }
                });
            }
        });

        // Change Trainer Role
        jQuery('.change-trainer-role').on('change', function() {
            const trainerId = jQuery(this).closest('.modern-list-item').data('trainer-id');
            const newRole = jQuery(this).val();
            jQuery.ajax({
                url: wptm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wptm_change_trainer_role',
                    nonce: wptm_ajax.nonce,
                    club_id: club.id,
                    member_id: trainerId,
                    role: newRole
                },
                success: function(response) {
                    console.log('Change trainer role response:', response);
                    if (response.success) {
                        loadClubDetails(club.id);
                        showToast('Member role updated successfully!', 'success');
                    } else {
                        showToast(response.data.message || 'Error changing member role.', 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error changing trainer role:', status, error);
                    showToast('An error occurred while changing the member role.', 'error');
                }
            });
        });

        // Cancel Invitation
        jQuery('.cancel-invitation').on('click', function() {
            if (confirm('Are you sure you want to cancel this invitation?')) {
                const invitationId = jQuery(this).closest('.modern-list-item').data('invitation-id');
                jQuery.ajax({
                    url: wptm_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wptm_cancel_invitation',
                        nonce: wptm_ajax.nonce,
                        club_id: club.id,
                        invitation_id: invitationId
                    },
                    success: function(response) {
                        console.log('Cancel invitation response:', response);
                        if (response.success) {
                            loadClubDetails(club.id);
                            showToast('Invitation cancelled successfully!', 'success');
                        } else {
                            showToast(response.data.message || 'Error cancelling invitation.', 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error cancelling invitation:', status, error);
                        showToast('An error occurred while cancelling the invitation.', 'error');
                    }
                });
            }
        });
    }

    
    // Hulpfunctie die we kunnen hergebruiken voor rolvertaling
    function getDisplayRoleFromRole(role) {
        switch(role) {
            case 'owner': return 'Owner';
            case 'manager': return 'Manager';
            case 'member': return 'Trainer';
            case 'sports_coordinator': return 'Sports Coordinator';
            default: return role ? role.charAt(0).toUpperCase() + role.slice(1) : 'Unknown';
        }
    }

    function renderInviteTrainerModal(clubId) {
        const modalId = 'wptm-invite-trainer-modal';
        let modal = $(`#${modalId}`);
        if (modal.length) modal.remove();
        modal = $(`<div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true"><div class="modal-dialog"><div class="modal-content modern-modal"><div class="modal-header modern-header"><h5 class="modal-title modern-title" id="${modalId}Label">Invite Team Member</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="mb-3"><label for="wptm-trainer-email" class="modern-label">Email:</label><input type="email" class="form-control modern-input" id="wptm-trainer-email" placeholder="Enter email address"></div><div class="mb-3"><label for="wptm-trainer-role" class="modern-label">Role:</label><select class="form-select modern-select" id="wptm-trainer-role">
            <option value="member">Trainer</option>
            <option value="sports_coordinator">Sports Coordinator</option>
            <option value="manager">Manager</option>
        </select></div><div class="mb-3"><p class="modern-label text-danger" id="wptm-invite-trainer-error" style="display: none;"></p></div></div><div class="modal-footer"><button type="button" class="modern-btn modern-btn-secondary" data-bs-dismiss="modal">Close</button><button type="button" class="modern-btn modern-btn-primary" id="wptm-invite-trainer-submit">Send Invitation</button></div></div></div></div>`);
        $('body').append(modal);
        const bootstrapModal = new bootstrap.Modal(modal[0]);
        bootstrapModal.show();

        modal.find('#wptm-invite-trainer-submit').on('click', function() {
            const email = $('#wptm-trainer-email').val().trim();
            const role = $('#wptm-trainer-role').val();
            const errorElement = $('#wptm-invite-trainer-error');
            errorElement.hide().text('');
            if (!email) {
                errorElement.text('Please enter an email address.').show();
                return;
            }
            if (!role) {
                errorElement.text('Please select a role.').show();
                return;
            }
            
            console.log(`Sending invitation to ${email} with role ${role}`);
            
            $.ajax({
                url: wptm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wptm_invite_trainer',
                    nonce: wptm_ajax.nonce,
                    club_id: clubId,
                    email: email,
                    role: role
                },
                success: function(response) {
                    console.log('Invite trainer response:', response);
                    if (response.success) {
                        bootstrapModal.hide();
                        loadClubDetails(clubId);
                        showToast('Invitation sent successfully!', 'success');
                    } else {
                        errorElement.text('Error sending invitation: ' + (response.data.message || 'Unknown error')).show();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error sending invitation:', status, error);
                    errorElement.text('An error occurred while sending the invitation.').show();
                }
            });
        });
    }

    // Nieuwe functies toevoegen voor Team Manager
    function checkTeamManagerAccess() {
        $.ajax({
            url: wptm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wptm_get_clubs',
                nonce: wptm_ajax.nonce
            },
            success: function(response) {
                console.log('wptm_get_clubs response for team manager access:', response);
                if (response.success) {
                    const clubs = response.data;
                    // Check if the user is a manager or owner of any club
                    const hasAccess = clubs.some(club => ['manager', 'owner'].includes(club.role));
                    if (hasAccess) {
                        $('#team-manager-tab').show();
                        console.log('User has access to Team Manager tab');
                    } else {
                        $('#team-manager-tab').hide();
                        console.log('User does not have access to Team Manager tab');
                    }
                } else {
                    $('#team-manager-tab').hide();
                    console.log('No clubs found or error fetching clubs, hiding Team Manager tab');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error in wptm_get_clubs for team manager access:', status, error);
                $('#team-manager-tab').hide();
            }
        });
    }

    function loadTeamManager() {
        console.log('Loading Team Manager for season:', selectedSeason);
        $('#team-manager-content').html('<div class="text-center"><span class="loading-spinner"></span> Loading teams...</div>');
        
        $.ajax({
            url: wptm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wptm_get_managed_teams',
                nonce: wptm_ajax.nonce,
                season: selectedSeason
            },
            success: function(response) {
                console.log('wptm_get_managed_teams response:', response);
                if (response.success) {
                    // Reset de trainer cache bij het laden van teams
                    window.teamTrainerCache = {};
                    
                    renderManagedTeams(response.data);
                    
                    // Vertraag de trainer count verificatie om ervoor te zorgen dat de DOM is bijgewerkt
                    setTimeout(verifyTrainerCount, 500);
                } else {
                    $('#team-manager-content').html('<div class="alert alert-danger modern-alert">Failed to load teams: ' + (response.data.message || 'Unknown error') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading managed teams:', status, error);
                $('#team-manager-content').html('<div class="alert alert-danger modern-alert">Failed to load teams. Please try again later.</div>');
            }
        });
    }

    // Functie om te controleren of de trainer counts correct zijn
    function verifyTrainerCount() {
        console.log('Verifying trainer counts for all teams...');
        
        const teamElements = $('#managed-teams-list .modern-list-item');
        if (teamElements.length === 0) {
            console.log('No teams found to verify trainer counts');
            return;
        }
        
        console.log('Found ' + teamElements.length + ' teams to verify');
        
        teamElements.each(function() {
            const teamId = $(this).data('team-id');
            if (!teamId) {
                console.log('Team element without team-id attribute:', this);
                return;
            }
            
            console.log('Verifying trainer count for team ID:', teamId);
            
            // Direct database check via AJAX
            $.ajax({
                url: wptm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wptm_verify_trainer_count',
                    nonce: wptm_ajax.nonce,
                    team_id: teamId,
                    season: selectedSeason
                },
                success: function(response) {
                    console.log('Team ID ' + teamId + ' trainer count verification:', response);
                    if (response.success) {
                        const actualCount = response.data.count;
                        const displayedCountElement = $(`#managed-teams-list .modern-list-item[data-team-id="${teamId}"] .trainer-count`);
                        
                        if (displayedCountElement.length === 0) {
                            console.log('Could not find trainer count element for team ID ' + teamId);
                            return;
                        }
                        
                        const displayedCount = displayedCountElement.text();
                        console.log('Team ID ' + teamId + ': Displayed count = ' + displayedCount + ', Actual count = ' + actualCount);
                        
                        // Update the count if different
                        if (displayedCount !== String(actualCount)) {
                            displayedCountElement.text(actualCount);
                            console.log('Team ID ' + teamId + ': Updated count to ' + actualCount);
                        }
                    } else {
                        console.error('Error response from trainer count verification:', response);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error verifying trainer count for team ID ' + teamId + ':', status, error);
                }
            });
        });
    }

    function renderManagedTeams(teams) {
        console.log('Rendering ' + (teams ? teams.length : 0) + ' managed teams');
        
        let teamsHtml = `
            <div class="row">
                <div class="col-md-4">
                    <div class="card modern-card">
                        <div class="card-header modern-header">
                            <div class="d-flex align-items-center">
                                <h5 class="card-title modern-title mb-0">Teams</h5>
                                <button class="modern-btn modern-btn-primary add-managed-team-btn ms-2" title="Add Team">
                                    <i class="fa-solid fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <ul class="list-group modern-list-group" id="managed-teams-list">`;
        
        if (teams && teams.length > 0) {
            teams.forEach(team => {
                const trainerCount = team.trainer_count || 0;
                const coachName = team.coach || 'Not assigned';
                console.log('Rendering team:', team.id, team.team_name, 'with trainer count:', trainerCount);
                
                teamsHtml += `
                    <li class="list-group-item modern-list-item d-flex justify-content-between align-items-center" 
                        data-team-id="${team.id}" data-wc-team-id="${team.wc_team_id || ''}">
                        <span class="team-info">${team.team_name} (Trainers: <span class="trainer-count">${trainerCount}</span>) Coach: ${coachName}</span>
                    </li>`;
            });
        } else {
            teamsHtml += '<li class="list-group-item modern-list-item text-muted">No teams found. Add a team.</li>';
        }
        
        teamsHtml += `
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div id="team-manager-details" class="card modern-card" style="display: none;">
                        <div class="card-header modern-header">
                            <h5 class="card-title modern-title mb-0">Team Trainers</h5>
                        </div>
                        <div class="card-body">
                            <div id="team-trainers-content">
                                <p class="text-muted">Select a team to see assigned trainers.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>`;
        
        $('#team-manager-content').html(teamsHtml);
        
        // Event handlers
        $('.add-managed-team-btn').on('click', function() {
            renderAddManagedTeamModal();
        });
        
        $('#managed-teams-list .modern-list-item').on('click', function() {
            const teamId = $(this).data('team-id');
            if (!teamId) {
                console.error('Clicked on team item without team-id attribute:', this);
                return;
            }
            
            console.log('Team clicked:', teamId);
            $('#managed-teams-list .modern-list-item').removeClass('selected-team');
            $(this).addClass('selected-team');
            
            // Leeg de cache voor deze specifieke team
            if (window.teamTrainerCache) {
                delete window.teamTrainerCache[teamId + '_' + selectedSeason];
            }
            
            loadTeamTrainers(teamId);
        });
        
        // Auto-select first team if available
        if (teams && teams.length > 0) {
            console.log('Auto-selecting first team:', teams[0].id);
            $('#managed-teams-list .modern-list-item:first').addClass('selected-team');
            loadTeamTrainers(teams[0].id);
        }
    }

    function renderAddManagedTeamModal() {
        const modalId = 'wptm-add-managed-team-modal';
        let modal = $(`#${modalId}`);
        if (modal.length) modal.remove();
        
        modal = $(`
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content modern-modal">
                        <div class="modal-header modern-header">
                            <h5 class="modal-title modern-title" id="${modalId}Label">Add New Team</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="wptm-managed-team-name" class="modern-label">Team Name:</label>
                                <input type="text" class="form-control modern-input" id="wptm-managed-team-name" placeholder="Enter team name">
                            </div>
                            <div class="mb-3">
                                <label for="wptm-managed-team-coach" class="modern-label">Coach Name:</label>
                                <input type="text" class="form-control modern-input" id="wptm-managed-team-coach" placeholder="Enter coach name">
                            </div>
                            <div class="mb-3">
                                <p class="modern-label text-danger" id="wptm-add-managed-team-error" style="display: none;"></p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="modern-btn modern-btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="modern-btn modern-btn-primary" id="wptm-add-managed-team-submit">Add Team</button>
                        </div>
                    </div>
                </div>
            </div>`);
        
        $('body').append(modal);
        const bootstrapModal = new bootstrap.Modal(modal[0]);
        bootstrapModal.show();
        
        modal.find('#wptm-add-managed-team-submit').on('click', function() {
            const teamName = $('#wptm-managed-team-name').val().trim();
            const coachName = $('#wptm-managed-team-coach').val().trim();
            const errorElement = $('#wptm-add-managed-team-error');
            
            errorElement.hide().text('');
            
            if (!teamName) {
                errorElement.text('Please enter a team name.').show();
                return;
            }
            
            $.ajax({
                url: wptm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wptm_add_managed_team',
                    nonce: wptm_ajax.nonce,
                    team_name: teamName,
                    coach: coachName,
                    season: selectedSeason
                },
                success: function(response) {
                    console.log('Add managed team response:', response);
                    if (response.success && response.data.team_id > 0) {
                        bootstrapModal.hide();
                        loadTeamManager();
                        showToast('Team added successfully!', 'success');
                    } else {
                        errorElement.text('Error adding team: ' + (response.data.message || 'Unknown error')).show();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error adding managed team:', status, error);
                    errorElement.text('An error occurred while adding the team.').show();
                }
            });
        });
    }

    function loadTeamTrainers(teamId) {
        $('#team-manager-details').show();
        $('#team-trainers-content').html('<div class="text-center"><span class="loading-spinner"></span> Loading trainers...</div>');
        
        console.log('Loading trainers for team ID:', teamId, 'and season:', selectedSeason);
        
        // Haal de trainers op die aan dit team zijn toegewezen
        $.ajax({
            url: wptm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wptm_get_team_trainers',
                nonce: wptm_ajax.nonce,
                team_id: teamId,
                season: selectedSeason
            },
            success: function(response) {
                console.log('Get team trainers response:', response);
                
                // Haal alle beschikbare trainers op om te kunnen tonen in de assign trainer modal
                $.ajax({
                    url: wptm_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wptm_get_available_trainers',
                        nonce: wptm_ajax.nonce,
                        team_id: teamId
                    },
                    success: function(availableResponse) {
                        console.log('Get available trainers response:', availableResponse);
                        
                        if (response.success && availableResponse.success) {
                            // Log de daadwerkelijke trainers data
                            console.log('Assigned trainers data:', response.data);
                            console.log('Available trainers data:', availableResponse.data);
                            
                            renderTeamTrainers(teamId, response.data, availableResponse.data);
                        } else {
                            const errorMessage = response.success ? 
                                (availableResponse.data.message || 'Failed to load available trainers') : 
                                (response.data.message || 'Failed to load team trainers');
                                
                            $('#team-trainers-content').html('<div class="alert alert-danger modern-alert">' + errorMessage + '</div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading available trainers:', status, error);
                        $('#team-trainers-content').html('<div class="alert alert-danger modern-alert">Failed to load available trainers.</div>');
                    }
                });
            },
            error: function(xhr, status, error) {
                console.error('Error loading team trainers:', status, error);
                $('#team-trainers-content').html('<div class="alert alert-danger modern-alert">Failed to load team trainers.</div>');
            }
        });
    }

    function renderTeamTrainers(teamId, assignedTrainers, availableTrainers) {
        // Log detail info for debugging
        console.log('Rendering team trainers for team ID:', teamId);
        console.log('Assigned trainers data:', assignedTrainers);
        console.log('Assigned trainers count:', assignedTrainers ? assignedTrainers.length : 0);
        console.log('Available trainers count:', availableTrainers ? availableTrainers.length : 0);
        
        let html = `
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">Assigned Trainers</h6>
                <button class="modern-btn modern-btn-primary assign-trainer-btn" data-team-id="${teamId}">
                    <i class="fa-solid fa-plus"></i> Assign Trainer
                </button>
            </div>
            <ul class="list-group modern-list-group mb-4" id="assigned-trainers-list">`;
        
        if (assignedTrainers && assignedTrainers.length > 0) {
            assignedTrainers.forEach(trainer => {
                console.log('Processing trainer for display:', trainer);
                
                const trainerId = trainer.trainer_user_id || 0;
                const trainerName = trainer.name || 'Unknown';
                const trainerEmail = trainer.email || 'No email';
                
                // Gebruik de display_role als die er is, anders gebruik de getDisplayRoleFromRole helper
                // voor de team rol (positie/functie in het team)
                const teamRole = trainer.role || 'Not specified';
                const teamRoleDisplay = trainer.display_role || getDisplayRoleFromRole(teamRole);
                
                // Voor de club rol (lid van de club) - dit moet overeenkomen met Sports Coordinator
                // als de trainer die rol heeft in de club
                const clubRole = trainer.club_role || 'member';
                const clubRoleDisplay = trainer.club_display_role || getDisplayRoleFromRole(clubRole);
                
                const isCoordinator = trainer.is_coordinator || clubRole === 'sports_coordinator';
                const displayClubRole = isCoordinator ? 'Sports Coordinator' : clubRoleDisplay;
                
                const additionalInfo = trainer.additional_info || '';
                
                html += `
                    <li class="list-group-item modern-list-item d-flex justify-content-between align-items-center" 
                        data-trainer-id="${trainerId}">
                        <div>
                            <strong>${trainerName}</strong>
                            <br><small>${trainerEmail}</small>
                            <br><small>Team Function: ${teamRoleDisplay}</small>
                            <br><small>Club Role: ${displayClubRole}</small>
                            ${additionalInfo ? `<br><small>Additional info: ${additionalInfo}</small>` : ''}
                        </div>
                        <button class="modern-btn modern-btn-danger remove-trainer-btn" title="Remove Trainer" data-trainer-id="${trainerId}" data-team-id="${teamId}">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </li>`;
            });
        } else {
            html += '<li class="list-group-item modern-list-item text-muted">No trainers assigned to this team.</li>';
        }
        
        html += `</ul>`;
        
        $('#team-trainers-content').html(html);
        
        // Bijwerken van trainer count in teams lijst
        const trainerCount = assignedTrainers ? assignedTrainers.length : 0;
        console.log('Updating trainer count for team ID:', teamId, 'to', trainerCount);
        $(`#managed-teams-list .modern-list-item[data-team-id="${teamId}"] .trainer-count`).text(trainerCount);
        
        // Event handlers
        $('.assign-trainer-btn').on('click', function() {
            const teamId = $(this).data('team-id');
            renderAssignTrainerModal(teamId, assignedTrainers || [], availableTrainers || []);
        });
        
        $('.remove-trainer-btn').on('click', function() {
            const trainerId = $(this).data('trainer-id');
            const teamId = $(this).data('team-id');
            
            console.log(`Removing trainer: ${trainerId} from team: ${teamId}`);
            
            if (confirm('Are you sure you want to remove this trainer from the team?')) {
                removeTrainerFromTeam(teamId, trainerId);
            }
        });
    }

    function renderAssignTrainerModal(teamId, assignedTrainers, availableTrainers) {
        // Log alle beschikbare trainers voor debugging
        console.log('Available trainers before filtering:', availableTrainers);
        console.log('Already assigned trainers:', assignedTrainers);
        
        // Filter de trainers die nog niet toegewezen zijn
        const assignedTrainerIds = assignedTrainers.map(trainer => 
            trainer.trainer_user_id ? parseInt(trainer.trainer_user_id) : 0
        ).filter(Boolean);
        
        console.log('Assigned trainer IDs:', assignedTrainerIds);
        
        const filteredTrainers = availableTrainers.filter(trainer => {
            // Gebruik Math.abs om ook met negatieve IDs (uitnodigingen) te werken
            const trainerId = Math.abs(parseInt(trainer.id));
            const isAssigned = assignedTrainerIds.includes(trainerId);
            console.log(`Trainer ${trainer.name} (ID: ${trainer.id}) - Is already assigned: ${isAssigned}`);
            return !isAssigned;
        });
        
        console.log('Filtered available trainers:', filteredTrainers);
        
        const modalId = 'wptm-assign-trainer-modal';
        let modal = $(`#${modalId}`);
        if (modal.length) modal.remove();
        
        let trainerOptions = '';
        if (filteredTrainers.length === 0) {
            trainerOptions = '<option value="" disabled>No available trainers found</option>';
        } else {
            filteredTrainers.forEach(trainer => {
                // Vertaal de club rol naar een aangepaste weergave
                const clubRole = trainer.role || 'member';
                const clubRoleDisplay = getDisplayRoleFromRole(clubRole);
                
                const statusText = trainer.status === 'invited' ? ' (Invited)' : '';
                const isDisabled = trainer.status === 'invited' && parseInt(trainer.id) < 0;
                trainerOptions += `<option value="${trainer.id}" data-name="${trainer.name}" data-email="${trainer.email}" data-role="${trainer.role}" ${isDisabled ? 'disabled' : ''}>
                    ${trainer.name}${statusText} (${trainer.email}, Club Role: ${clubRoleDisplay})
                </option>`;
            });
        }
        
        modal = $(`
            <div class="modal fade" id="${modalId}" tabindex="-1" aria-labelledby="${modalId}Label" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content modern-modal">
                        <div class="modal-header modern-header">
                            <h5 class="modal-title modern-title" id="${modalId}Label">Assign Trainer to Team</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="wptm-trainer-select" class="modern-label">Choose a trainer:</label>
                                <select class="form-select modern-select" id="wptm-trainer-select">
                                    <option value="">-- Select a trainer --</option>
                                    ${trainerOptions}
                                </select>
                                <div class="form-text text-info mt-1">
                                    Note: Invited trainers who haven't accepted yet are shown but cannot be assigned until they accept the invitation.
                                </div>
                            </div>
                            <div class="mb-3 trainer-details" style="display: none;">
                                <label for="wptm-trainer-position" class="modern-label">Function/Position in this team:</label>
                                <input type="text" class="form-control modern-input mb-2" id="wptm-trainer-position" placeholder="e.g., Head Coach, Assistant Coach, Goalkeeper Trainer">
                                <small class="form-text text-muted">This defines what the trainer will do in this specific team.</small>
                                
                                <label for="wptm-trainer-club-role" class="modern-label mt-3">Club Role:</label>
                                <select class="form-select modern-select mb-2" id="wptm-trainer-club-role">
                                    <option value="trainer">Trainer</option>
                                    <option value="sports_coordinator">Sports Coordinator</option>
                                    <option value="manager">Manager</option>
                                </select>
                                <small class="form-text text-muted">This defines the trainer's role in the club.</small>
                                
                                <label for="wptm-trainer-additional-info" class="modern-label mt-3">Additional information (optional):</label>
                                <input type="text" class="form-control modern-input mb-2" id="wptm-trainer-additional-info" placeholder="Additional details about this trainer's role">
                            </div>
                            <div class="mb-3">
                                <p class="modern-label text-danger" id="wptm-assign-trainer-error" style="display: none;"></p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="modern-btn modern-btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="button" class="modern-btn modern-btn-primary" id="wptm-assign-trainer-submit">Assign Trainer</button>
                        </div>
                    </div>
                </div>
            </div>`);
        
        $('body').append(modal);
        const bootstrapModal = new bootstrap.Modal(modal[0]);
        bootstrapModal.show();
        
        modal.find('#wptm-trainer-select').on('change', function() {
            const trainerId = $(this).val();
            console.log('Selected trainer ID:', trainerId);
            if (trainerId) {
                modal.find('.trainer-details').show();
                
                // Pre-populate club_role if available
                const selectedOption = $(this).find('option:selected');
                const trainerRole = selectedOption.data('role');
                
                // Bepaal het juiste dropdown item op basis van de rol
                let clubRoleToSelect = 'trainer'; // Default
                if (trainerRole === 'sports_coordinator') {
                    clubRoleToSelect = 'sports_coordinator';
                } else if (trainerRole === 'manager') {
                    clubRoleToSelect = 'manager';
                } else if (trainerRole === 'owner') {
                    clubRoleToSelect = 'owner';
                }
                
                $('#wptm-trainer-club-role').val(clubRoleToSelect);
            } else {
                modal.find('.trainer-details').hide();
            }
        });
        
        modal.find('#wptm-assign-trainer-submit').on('click', function() {
            const trainerId = $('#wptm-trainer-select').val();
            const role = $('#wptm-trainer-position').val().trim();
            const clubRole = $('#wptm-trainer-club-role').val();
            const additionalInfo = $('#wptm-trainer-additional-info').val().trim();
            const errorElement = $('#wptm-assign-trainer-error');
            
            errorElement.hide().text('');
            
            if (!trainerId) {
                errorElement.text('Please select a trainer.').show();
                return;
            }
            
            // Controleer of het een negatieve ID is (uitnodiging die nog niet is geaccepteerd)
            if (parseInt(trainerId) < 0) {
                errorElement.text('This trainer has been invited but has not yet accepted the invitation. Please wait for them to accept before assigning them to a team.').show();
                return;
            }
            
            if (!role) {
                errorElement.text('Please enter a function/position for this trainer in the team.').show();
                return;
            }
            
            console.log('Submitting assign trainer request with:', {
                teamId: teamId,
                trainerId: trainerId,
                role: role,
                clubRole: clubRole,
                additionalInfo: additionalInfo,
                season: selectedSeason
            });
            
            $.ajax({
                url: wptm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wptm_assign_trainer_to_team',
                    nonce: wptm_ajax.nonce,
                    team_id: teamId,
                    trainer_id: trainerId,
                    position: role,
                    club_role: clubRole,
                    additional_info: additionalInfo,
                    season: selectedSeason
                },
                success: function(response) {
                    console.log('Assign trainer to team response:', response);
                    if (response.success) {
                        bootstrapModal.hide();
                        loadTeamTrainers(teamId);
                        
                        if (response.data && response.data.updated) {
                            showToast('Trainer information updated successfully!', 'success');
                        } else {
                            showToast('Trainer assigned successfully!', 'success');
                        }
                    } else {
                        errorElement.text('Error assigning trainer: ' + (response.data.message || 'Unknown error')).show();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error assigning trainer to team:', status, error);
                    errorElement.text('An error occurred while assigning the trainer.').show();
                }
            });
        });
    }

    function removeTrainerFromTeam(teamId, trainerId) {
        console.log(`Removing trainer (ID: ${trainerId}) from team (ID: ${teamId}), season: ${selectedSeason}`);
        
        $.ajax({
            url: wptm_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wptm_remove_trainer_from_team',
                nonce: wptm_ajax.nonce,
                team_id: teamId,
                trainer_id: trainerId,
                season: selectedSeason
            },
            success: function(response) {
                console.log('Remove trainer from team response:', response);
                if (response.success) {
                    loadTeamTrainers(teamId);
                    showToast('Trainer removed successfully!', 'success');
                } else {
                    showToast('Error removing trainer: ' + (response.data.message || 'Unknown error'), 'error');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error removing trainer from team:', status, error);
                showToast('An error occurred while removing the trainer.', 'error');
            }
        });
    }

    function verifyTrainerCount() {
        console.log('Verifying trainer counts for all teams...');
        
        const teamElements = $('#managed-teams-list .modern-list-item');
        teamElements.each(function() {
            const teamId = $(this).data('team-id');
            
            // Direct database check via AJAX (nieuwe functie nodig in PHP)
            $.ajax({
                url: wptm_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wptm_verify_trainer_count',
                    nonce: wptm_ajax.nonce,
                    team_id: teamId,
                    season: selectedSeason
                },
                success: function(response) {
                    console.log(`Team ID ${teamId} trainer count verification:`, response);
                    if (response.success) {
                        const actualCount = response.data.count;
                        const displayedCount = $(`#managed-teams-list .modern-list-item[data-team-id="${teamId}"] .trainer-count`).text();
                        console.log(`Team ID ${teamId}: Displayed count = ${displayedCount}, Actual count = ${actualCount}`);
                        
                        // Update the count if different
                        if (displayedCount !== String(actualCount)) {
                            $(`#managed-teams-list .modern-list-item[data-team-id="${teamId}"] .trainer-count`).text(actualCount);
                            console.log(`Team ID ${teamId}: Updated count to ${actualCount}`);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error(`Error verifying trainer count for team ID ${teamId}:`, status, error);
                }
            });
        });
    }

     // Update de tabwijziging handler om Team Manager tabblad te ondersteunen
    $('#wptm-team-tabs a').on('shown.bs.tab', function(e) {
        activeTab = $(e.target).attr('id') === 'my-teams-tab' ? 'my-teams' : 
                    $(e.target).attr('id') === 'club-teams-tab' ? 'club-teams' : 
                    $(e.target).attr('id') === 'trainer-management-tab' ? 'trainer-management' :
                    'team-manager';
        
        if (activeTab === 'my-teams') {
            clubTeamsCurrentTeamId = null;
            clubTeamsCurrentPlayerId = null;
            $('#trainer-management-content').empty();
            $('#team-manager-content').empty();
        } else if (activeTab === 'club-teams') {
            myTeamsCurrentTeamId = null;
            myTeamsCurrentPlayerId = null;
            $('#trainer-management-content').empty();
            $('#team-manager-content').empty();
        } else if (activeTab === 'trainer-management') {
            myTeamsCurrentTeamId = null;
            myTeamsCurrentPlayerId = null;
            clubTeamsCurrentTeamId = null;
            clubTeamsCurrentPlayerId = null;
            $(`#my-teams-players-list`).empty();
            $(`#my-teams-player-details`).empty();
            $(`#my-teams-spider-chart`).empty();
            $(`#my-teams-player-history`).empty();
            $(`#club-teams-players-list`).empty();
            $(`#club-teams-player-details`).empty();
            $(`#club-teams-spider-chart`).empty();
            $(`#club-teams-player-history`).empty();
            $('#team-manager-content').empty();
            loadTrainerManagement();
            return;
        } else if (activeTab === 'team-manager') {
            myTeamsCurrentTeamId = null;
            myTeamsCurrentPlayerId = null;
            clubTeamsCurrentTeamId = null;
            clubTeamsCurrentPlayerId = null;
            $(`#my-teams-players-list`).empty();
            $(`#my-teams-player-details`).empty();
            $(`#my-teams-spider-chart`).empty();
            $(`#my-teams-player-history`).empty();
            $(`#club-teams-players-list`).empty();
            $(`#club-teams-player-details`).empty();
            $(`#club-teams-spider-chart`).empty();
            $(`#club-teams-player-history`).empty();
            $('#trainer-management-content').empty();
            loadTeamManager();
            return;
        }
        
        $(`#${activeTab}-players-list`).empty();
        $(`#${activeTab}-player-details`).empty();
        $(`#${activeTab}-spider-chart`).empty();
        $(`#${activeTab}-player-history`).empty();
        loadTeams(activeTab);
    });

    // Update handler voor seizoenswijziging om Team Manager te ondersteunen
    $('#wptm-season-select').on('change', function() {
        const newYear = $(this).val();
        if (newYear !== currentYear) {
            currentYear = parseInt(newYear);
            selectedSeason = currentYear + '-' + (currentYear + 1);
            console.log('Season changed to:', selectedSeason);
            localStorage.setItem('selectedSeason', selectedSeason);
            myTeamsCurrentTeamId = null;
            clubTeamsCurrentTeamId = null;
            myTeamsCurrentPlayerId = null;
            clubTeamsCurrentPlayerId = null;
            $(`#${activeTab}-players-list`).empty();
            $(`#${activeTab}-player-details`).empty();
            $(`#${activeTab}-spider-chart`).empty();
            $(`#${activeTab}-player-history`).empty();
            
            if (activeTab === 'trainer-management') {
                $('#trainer-management-content').empty();
                loadTrainerManagement();
            } else if (activeTab === 'team-manager') {
                $('#team-manager-content').empty();
                loadTeamManager();
            } else {
                loadTeams(activeTab);
            }
        }
    });

    initializeSeason();
    initializeTabs();
});