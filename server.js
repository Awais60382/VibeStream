let currentVideoId = 'video1';

window.onload = function() {
    fetchVideoStats();
};

async function fetchVideoStats() {
    try {
        let response = await fetch('code.php?action=get_all');
        let data = await response.json();
        
        for (let id in data) {
            let span = document.getElementById(`${id}-views`);
            if (span) span.innerText = data[id].views.toLocaleString();
        }
        
        let mainViews = document.getElementById('main-views');
        if (data[currentVideoId] && mainViews) {
            mainViews.innerText = data[currentVideoId].views.toLocaleString();
        }
    } catch (err) {
        console.error("Error fetching stats:", err);
    }
}

async function changeVideo(videoUrl, titleText, videoId) {
    const player = document.getElementById('active-video');
    const title = document.getElementById('video-title');
    
    if (player) {
        player.src = videoUrl;
        player.play().catch(e => console.log("Playback notice:", e));
    }
    
    if (title) {
        title.innerText = titleText;
    }
    
    currentVideoId = videoId;

    try {
        let response = await fetch(`code.php?action=watch&id=${videoId}`);
        let data = await response.json();
        if (data.success) {
            let mainViews = document.getElementById('main-views');
            let targetSpan = document.getElementById(`${videoId}-views`);
            
            if (mainViews) mainViews.innerText = data.views.toLocaleString();
            if (targetSpan) targetSpan.innerText = data.views.toLocaleString();
        }
    } catch (err) {
        console.error("Error updating view count:", err);
    }
}

async function handleLogin(isGuest) {
    let usernameInput = document.getElementById('username-input').value.trim();
    if (!isGuest && !usernameInput) {
        alert("Please enter a username!");
        return;
    }

    try {
        let response = await fetch('code.php?action=auth', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: usernameInput, isGuest: isGuest })
        });
        let data = await response.json();
        
        if (data.success) {
            document.getElementById('display-user').innerText = data.user.username;
            closeModal();
        } else {
            alert("Login failed: " + (data.message || 'Unknown error'));
        }
    } catch (err) {
        console.error("Login request failed:", err);
        let fallbackName = isGuest ? "Guest_" + Math.floor(Math.random() * 9000 + 1000) : usernameInput;
        document.getElementById('display-user').innerText = fallbackName;
        closeModal();
    }
}

function closeModal() {
    document.getElementById('auth-modal').style.display = 'none';
}

function filterVideos() {
    let input = document.getElementById('search-input').value.toLowerCase();
    let cards = document.getElementsByClassName('video-card');

    for (let i = 0; i < cards.length; i++) {
        let title = cards[i].getElementsByTagName('h3')[0].innerText.toLowerCase();
        cards[i].style.display = title.includes(input) ? "" : "none";
    }
}

function filterCategory(categoryName, element) {
    const chips = document.querySelectorAll('.chip');
    chips.forEach(chip => chip.classList.remove('active'));
    
    element.classList.add('active');
    
    let selectedCat = categoryName.toLowerCase();
    let cards = document.getElementsByClassName('video-card');

    for (let i = 0; i < cards.length; i++) {
        let cardCategories = cards[i].getAttribute('data-category') || "";
        
        if (selectedCat === 'all' || cardCategories.includes(selectedCat)) {
            cards[i].style.display = "";
        } else {
            cards[i].style.display = "none";
        }
    }
}
function updateUserSession(username) {
    if (!username) return;

    const nameDisplay = document.getElementById('userNameDisplay');
    if (nameDisplay) {
        nameDisplay.textContent = username;
    }

    const avatarContainer = document.getElementById('userAvatar');
    if (avatarContainer) {
        const firstLetter = username.charAt(0).toUpperCase();
        avatarContainer.innerHTML = `<span class="avatar-initial">${firstLetter}</span>`;
    }
}
