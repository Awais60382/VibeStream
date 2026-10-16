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
    } catch (err) {
        console.error(err);
    }
}

async function handleLogin(isGuest) {
    if (!isGuest) {
        window.location.assign('login.php');
        return;
    }

    try {
        let response = await fetch('auth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ isGuest: true })
        });
        let data = await response.json();
        
        if (response.ok && data.success) {
            document.getElementById('display-user').innerText = data.user.username;
            closeModal();
        } else {
            alert("Guest sign-in failed: " + (data.message || 'Please try again.'));
        }
    } catch (err) {
        console.error("Guest sign-in request failed:", err);
        alert("Guest sign-in failed. Please try again.");
    }
}

function closeModal() {
    let modal = document.getElementById('auth-modal');
    if (modal) {
        modal.style.display = 'none';
    }
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
function switchVideo(element) {
    const videoPlayer = document.getElementById('main-player');
    const videoSource = document.getElementById('main-source');
    const videoTitle  = document.getElementById('main-title');
    const videoViews  = document.getElementById('main-views');

    if (!element || !videoPlayer) return;

    const newSrc   = element.getAttribute('data-src');
    const newTitle = element.getAttribute('data-title');
    const newViews = element.getAttribute('data-views');

    if (newSrc) {
        videoSource.src = newSrc;
        videoPlayer.load();
        
        if (videoTitle && newTitle) videoTitle.textContent = newTitle;
        if (videoViews && newViews) videoViews.textContent = newViews + " views";

        videoPlayer.play().catch(function(error) {
            console.warn("Autoplay blocked. User interaction required:", error);
        });
    }
}