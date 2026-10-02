const reportNode = document.getElementById('report-data');
const report = reportNode ? JSON.parse(reportNode.textContent) : null;

if (report && window.Chart) {
    Chart.defaults.font.family = 'Aptos, Segoe UI, sans-serif';
    Chart.defaults.color = '#78857d';
    Chart.defaults.borderColor = '#e8ece6';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 7;
    Chart.defaults.plugins.legend.labels.boxHeight = 7;

    const axis = {
        grid: { color: '#edf0eb', drawTicks: false },
        border: { display: false },
        ticks: { padding: 9, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 },
    };

    new Chart(document.getElementById('trend-chart'), {
        type: 'bar',
        data: {
            labels: report.days,
            datasets: [
                { type: 'line', label: 'Pages', data: report.pages, yAxisID: 'y', borderColor: '#4a8b68', backgroundColor: 'rgba(74, 139, 104, 0.12)', pointBackgroundColor: '#4a8b68', pointRadius: 2, pointHoverRadius: 4, borderWidth: 2, fill: true, tension: 0.34 },
                { label: 'Jobs', data: report.jobs, yAxisID: 'y1', backgroundColor: 'rgba(228, 173, 82, 0.72)', hoverBackgroundColor: '#d89e3f', borderRadius: 3, maxBarThickness: 13 },
            ],
        },
        options: {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', align: 'start', labels: { padding: 18 } } },
            scales: {
                x: { ...axis, grid: { display: false }, ticks: { ...axis.ticks, maxTicksLimit: 12 } },
                y: { ...axis, beginAtZero: true, position: 'left', title: { display: true, text: 'Pages' } },
                y1: { ...axis, beginAtZero: true, position: 'right', grid: { display: false }, ticks: { ...axis.ticks, precision: 0 }, title: { display: true, text: 'Jobs' } },
            },
        },
    });

    new Chart(document.getElementById('device-chart'), {
        type: 'bar',
        data: {
            labels: report.deviceNames,
            datasets: [{ label: 'Pages', data: report.devicePages, backgroundColor: ['#4a8b68', '#6a9e98', '#e4ad52', '#dc795f', '#93af63', '#74918a', '#b9a06b', '#819982'], borderRadius: 3, barThickness: 16 }],
        },
        options: {
            indexAxis: 'y',
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (context) => `${context.parsed.x.toLocaleString()} pages` } } },
            scales: {
                x: { ...axis, beginAtZero: true, ticks: { ...axis.ticks, maxTicksLimit: 5 } },
                y: { ...axis, grid: { display: false }, ticks: { ...axis.ticks, autoSkip: false } },
            },
        },
    });

    new Chart(document.getElementById('activity-chart'), {
        type: 'line',
        data: {
            labels: report.days,
            datasets: [
                { label: 'Mouse movements', data: report.movement, yAxisID: 'y', borderColor: '#6a9e98', backgroundColor: 'rgba(106, 158, 152, 0.12)', pointRadius: 1.5, borderWidth: 2, fill: true, tension: 0.32 },
                { label: 'Keystrokes', data: report.keystrokes, yAxisID: 'y1', borderColor: '#dc795f', backgroundColor: 'rgba(220, 121, 95, 0.08)', pointRadius: 1.5, borderWidth: 2, borderDash: [5, 3], fill: false, tension: 0.32 },
            ],
        },
        options: {
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', align: 'start', labels: { padding: 18 } } },
            scales: {
                x: { ...axis, grid: { display: false }, ticks: { ...axis.ticks, maxTicksLimit: 12 } },
                y: { ...axis, beginAtZero: true, title: { display: true, text: 'Movements' } },
                y1: { ...axis, beginAtZero: true, position: 'right', grid: { display: false }, title: { display: true, text: 'Keystrokes' } },
            },
        },
    });
}

document.getElementById('download-pdf')?.addEventListener('click', () => {
    if (!window.jspdf?.jsPDF || !report) {
        window.print();
        return;
    }

    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    const selectedDevice = document.querySelector('input[name="host"]')?.value.trim() || 'All hosts';
    const metricValues = [...document.querySelectorAll('.metric-value')].map((node) => node.textContent.trim());
    const pageWidth = pdf.internal.pageSize.getWidth();

    pdf.setFillColor(21, 33, 29);
    pdf.rect(0, 0, pageWidth, 37, 'F');
    pdf.setTextColor(246, 248, 244);
    pdf.setFontSize(9);
    pdf.text('PRINT TRACKER / DATABASE REPORT', 14, 12);
    pdf.setFontSize(22);
    pdf.text('Usage overview', 14, 24);
    pdf.setFontSize(9);
    pdf.setTextColor(202, 214, 205);
    pdf.text(`${report.period}    ·    ${selectedDevice}`, 14, 32);

    const metricLabels = ['Print jobs', 'Pages printed', 'Active devices', 'Avg. pages / job'];
    const tileWidth = (pageWidth - 36) / metricLabels.length;
    metricLabels.forEach((label, index) => {
        const x = 14 + index * (tileWidth + 2.5);
        pdf.setFillColor(244, 246, 241);
        pdf.roundedRect(x, 43, tileWidth, 22, 2, 2, 'F');
        pdf.setTextColor(104, 118, 108);
        pdf.setFontSize(8);
        pdf.text(label.toUpperCase(), x + 4, 51);
        pdf.setTextColor(27, 41, 34);
        pdf.setFontSize(15);
        pdf.text(metricValues[index] || '0', x + 4, 60);
    });

    pdf.setTextColor(27, 41, 34);
    pdf.setFontSize(11);
    pdf.text('Daily print volume', 14, 75);
    pdf.text('Pages by device', 153, 75);
    pdf.addImage(document.getElementById('trend-chart').toDataURL('image/png'), 'PNG', 14, 78, 135, 73);
    pdf.addImage(document.getElementById('device-chart').toDataURL('image/png'), 'PNG', 153, 78, 125, 73);

    if (typeof pdf.autoTable === 'function') {
        pdf.autoTable({
            startY: 155,
            head: [['Top devices', 'Print jobs', 'Pages']],
            body: report.topDevices.map((row) => [row.hostname, row.jobs.toLocaleString(), row.pages.toLocaleString()]),
            theme: 'grid',
            styles: { fontSize: 8, cellPadding: 2.5, textColor: [42, 57, 48], lineColor: [226, 232, 225] },
            headStyles: { fillColor: [36, 55, 46], textColor: [255, 255, 255] },
            margin: { left: 14, right: 14, bottom: 18 },
        });
    }

    pdf.addPage();
    pdf.setTextColor(27, 41, 34);
    pdf.setFontSize(15);
    pdf.text('Workstation activity', 14, 17);
    pdf.setFontSize(9);
    pdf.setTextColor(104, 118, 108);
    const movement = document.querySelector('.activity-totals div:first-child strong')?.textContent.trim() || '0';
    const keystrokes = document.querySelector('.activity-totals div:last-child strong')?.textContent.trim() || '0';
    pdf.text(`Mouse movements: ${movement}    ·    Keystrokes: ${keystrokes}`, 14, 24);
    pdf.addImage(document.getElementById('activity-chart').toDataURL('image/png'), 'PNG', 14, 29, 264, 82);

    if (typeof pdf.autoTable === 'function') {
        pdf.autoTable({
            startY: 117,
            head: [['Document', 'Device', 'Copies', 'Pages', 'Printed at']],
            body: report.recentJobs.map((row) => [row.document, row.hostname, row.copies.toLocaleString(), row.pages.toLocaleString(), row.timestamp]),
            theme: 'grid',
            styles: { fontSize: 8, cellPadding: 2.5, overflow: 'linebreak', textColor: [42, 57, 48], lineColor: [226, 232, 225] },
            headStyles: { fillColor: [36, 55, 46], textColor: [255, 255, 255] },
            margin: { left: 14, right: 14, bottom: 18 },
        });
    }

    pdf.addPage();
    pdf.setTextColor(27, 41, 34);
    pdf.setFontSize(15);
    pdf.text('Host activity and print totals', 14, 17);
    pdf.setFontSize(9);
    pdf.setTextColor(104, 118, 108);
    pdf.text(`${report.period}    ·    ${selectedDevice}`, 14, 24);

    if (typeof pdf.autoTable === 'function') {
        pdf.autoTable({
            startY: 31,
            head: [['Host', 'Activity logs', 'Mouse movements', 'Keystrokes', 'Print jobs', 'Pages printed']],
            body: report.hostSummary.map((row) => [
                row.hostname,
                row.activityLogs.toLocaleString(),
                row.movement.toLocaleString(),
                row.keystrokes.toLocaleString(),
                row.jobs.toLocaleString(),
                row.pages.toLocaleString(),
            ]),
            theme: 'grid',
            styles: { fontSize: 8, cellPadding: 2.5, overflow: 'linebreak', textColor: [42, 57, 48], lineColor: [226, 232, 225] },
            headStyles: { fillColor: [36, 55, 46], textColor: [255, 255, 255] },
            margin: { left: 14, right: 14, bottom: 18 },
        });
    }

    const pageHeight = pdf.internal.pageSize.getHeight();
    const footerText = '© 2026 Korbiz Solutions. Designed to support secure, scalable IT operations.';
    for (let pageNumber = 1; pageNumber <= pdf.getNumberOfPages(); pageNumber += 1) {
        pdf.setPage(pageNumber);
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(8);
        pdf.setTextColor(104, 118, 108);
        pdf.text(footerText, pageWidth / 2, pageHeight - 7, { align: 'center' });
    }

    pdf.save(`print-tracker-${report.period.replaceAll(' ', '')}.pdf`);
});