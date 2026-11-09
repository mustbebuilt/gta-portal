<x-portal-layout>
    <div style="margin-bottom: 24px;">
        <span class="badge badge-purple" style="margin-bottom: 6px;">Support &bull; Role-Based Instructions</span>
        <h2 style="font-size: 22px; font-weight: 700; color: var(--gta-text);">Help &amp; How To Guides</h2>
        <p style="font-size: 13px; color: var(--gta-muted); margin-top: 4px;">Clear step-by-step guidance tailored for your role ({{ ucfirst($userRole) }}). Read online or listen via device audio.</p>
    </div>

    <!-- Search & Category Filter -->
    <div class="gta-card" style="margin-bottom: 20px; padding: 14px;">
        <div style="display: flex; gap: 14px; align-items: center; flex-wrap: wrap;">
            <div style="flex: 2; min-width: 220px;">
                <input type="text" id="guideSearchInput" onkeyup="filterGuides()" placeholder="Search guides by title, keyword, or workflow..." class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
            </div>
            <div style="flex: 1; min-width: 180px;">
                <select id="categoryFilter" onchange="filterGuides()" class="form-control" style="width: 100%; padding: 8px 12px; font-size: 13px; border: 1px solid var(--gta-border); border-radius: 6px;">
                    <option value="">All Categories ({{ count($allGuides) }})</option>
                    @foreach($categories as $cat)
                        <option value="{{ strtolower($cat) }}">{{ $cat }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <!-- Guides List & Interactive Reader -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start;">
        <!-- Left: List of Guides -->
        <div style="display: flex; flex-direction: column; gap: 12px;" id="guidesList">
            @foreach($allGuides as $index => $guide)
                <div class="guide-item gta-card" data-title="{{ strtolower($guide['title']) }}" data-summary="{{ strtolower($guide['summary']) }}" data-category="{{ strtolower($guide['category']) }}" style="padding: 16px; cursor: pointer; transition: border-color 0.15s ease;" onclick="selectGuide({{ $index }})">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 6px;">
                        <h4 style="font-size: 15px; font-weight: 700; color: var(--gta-text);">{{ $guide['title'] }}</h4>
                        <span class="badge badge-blue" style="font-size: 10px;">{{ $guide['category'] }}</span>
                    </div>
                    <p style="font-size: 12px; color: var(--gta-muted); line-height: 1.5;">{{ $guide['summary'] }}</p>
                    <div style="margin-top: 10px; font-size: 11px; color: var(--gta-steel-blue); font-weight: 600;">
                        Read Steps ({{ count($guide['steps'] ?? []) }}) &rarr;
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Right: Guide Reader Panel with Text-to-Speech -->
        <div class="gta-card" id="guideReaderPanel" style="position: sticky; top: 80px;">
            <div id="readerPlaceholder" style="padding: 40px 20px; text-align: center; color: var(--gta-muted);">
                <p style="font-size: 14px;">Select a guide from the list to view its step-by-step instructions.</p>
            </div>

            <div id="readerContent" style="display: none;">
                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px; border-bottom: 1px solid var(--gta-border); padding-bottom: 12px;">
                    <div>
                        <span class="badge badge-purple" id="readerCategory" style="font-size: 11px;">Category</span>
                        <h3 id="readerTitle" style="font-size: 18px; font-weight: 700; margin-top: 6px;">Guide Title</h3>
                    </div>
                </div>

                <p id="readerSummary" style="font-size: 13px; color: var(--gta-muted); line-height: 1.6; margin-bottom: 16px;"></p>

                <h4 style="font-size: 13px; font-weight: 700; text-transform: uppercase; color: var(--gta-text); margin-bottom: 10px;">Step-by-step instructions</h4>
                <ol id="readerSteps" style="margin-left: 20px; line-height: 1.7; font-size: 13px; margin-bottom: 20px; display: flex; flex-direction: column; gap: 8px;"></ol>

                <div id="readerNoteWrap" style="background: var(--gta-bg); border-left: 3px solid var(--gta-accent); padding: 12px; border-radius: 4px; font-size: 12px; margin-bottom: 20px;">
                    <strong>Important Note:</strong>
                    <p id="readerNote" style="margin-top: 4px; color: var(--gta-muted);"></p>
                </div>

                <!-- Text to Speech Audio Player -->
                <div style="background: var(--gta-bg); border-radius: 6px; padding: 14px; border: 1px solid var(--gta-border);">
                    <div style="font-size: 12px; font-weight: 700; margin-bottom: 6px;">Listen to this guide</div>
                    <p style="font-size: 11px; color: var(--gta-muted); margin-bottom: 10px;">Uses your browser's native text-to-speech engine.</p>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" onclick="speakCurrentGuide()" class="gta-btn gta-btn-secondary" style="font-size: 12px; padding: 6px 14px;">🔊 Read Aloud</button>
                        <button type="button" onclick="stopSpeech()" class="gta-btn gta-btn-secondary" style="font-size: 12px; padding: 6px 14px;">⏹ Stop</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const guidesData = @json($allGuides);
        let currentGuideIndex = null;

        function selectGuide(index) {
            currentGuideIndex = index;
            const guide = guidesData[index];
            if (!guide) return;

            document.getElementById('readerPlaceholder').style.display = 'none';
            document.getElementById('readerContent').style.display = 'block';

            document.getElementById('readerCategory').textContent = guide.category;
            document.getElementById('readerTitle').textContent = guide.title;
            document.getElementById('readerSummary').textContent = guide.summary;

            const stepsList = document.getElementById('readerSteps');
            stepsList.innerHTML = '';
            (guide.steps || []).forEach(step => {
                const li = document.createElement('li');
                li.textContent = step;
                stepsList.appendChild(li);
            });

            const noteWrap = document.getElementById('readerNoteWrap');
            if (guide.note) {
                noteWrap.style.display = 'block';
                document.getElementById('readerNote').textContent = guide.note;
            } else {
                noteWrap.style.display = 'none';
            }

            // Highlight selected card
            document.querySelectorAll('.guide-item').forEach((card, idx) => {
                card.style.borderColor = idx === index ? 'var(--gta-accent)' : 'var(--gta-border)';
            });
        }

        function filterGuides() {
            const search = document.getElementById('guideSearchInput').value.toLowerCase();
            const category = document.getElementById('categoryFilter').value.toLowerCase();
            const items = document.querySelectorAll('.guide-item');

            items.forEach(item => {
                const title = item.getAttribute('data-title');
                const summary = item.getAttribute('data-summary');
                const cat = item.getAttribute('data-category');

                const matchesSearch = title.includes(search) || summary.includes(search);
                const matchesCat = !category || cat === category;

                item.style.display = matchesSearch && matchesCat ? 'block' : 'none';
            });
        }

        function speakCurrentGuide() {
            if (currentGuideIndex === null || !('speechSynthesis' in window)) return;
            window.speechSynthesis.cancel();

            const guide = guidesData[currentGuideIndex];
            const textToSpeak = `${guide.title}. ${guide.summary}. Steps: ` + (guide.steps || []).join('. ');

            const utterance = new SpeechSynthesisUtterance(textToSpeak);
            utterance.rate = 1.0;
            utterance.lang = 'en-GB';
            window.speechSynthesis.speak(utterance);
        }

        function stopSpeech() {
            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();
            }
        }

        // Auto select first guide on page load
        if (guidesData.length > 0) {
            selectGuide(0);
        }
    </script>
</x-portal-layout>

