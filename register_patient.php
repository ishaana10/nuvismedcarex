<?php
$pageTitle = "Register New Patient - NuvisMedcareX";
$activePage = "register-patient";
include __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <!-- Header Title -->
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-on-surface">New Patient Registration</h1>
            <p class="text-xs text-outline font-medium">Create a new medical chart and generate patient MRN file</p>
        </div>
        <a href="patients.php" class="px-3.5 py-2 bg-surface-container-high text-on-surface text-xs font-semibold rounded-xl hover:bg-surface-container-highest transition flex items-center gap-1.5">
            <span class="material-symbols-outlined text-base">arrow_back</span>
            <span>Back to Directory</span>
        </a>
    </div>

    <!-- Registration Form Card -->
    <form action="actions/patient_add.php" method="POST" class="card-container space-y-6">
        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">

        <!-- Section 1: Demographics -->
        <div>
            <h2 class="section-title mb-4">
                <span class="material-symbols-outlined text-blue-600 text-lg">person</span>
                <span>1. Personal & Demographics</span>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-group">
                    <label class="form-label">First Name <span class="text-red-500">*</span></label>
                    <input type="text" name="first_name" required placeholder="e.g. Sarah" class="form-input">
                </div>

                <div class="form-group">
                    <label class="form-label">Last Name <span class="text-red-500">*</span></label>
                    <input type="text" name="last_name" required placeholder="e.g. Jenkins" class="form-input">
                </div>

                <div class="form-group">
                    <label class="form-label">Date of Birth <span class="text-red-500">*</span></label>
                    <input type="date" name="dob" required class="form-input">
                </div>

                <div class="form-group">
                    <label class="form-label">Gender <span class="text-red-500">*</span></label>
                    <select name="gender" required class="form-select">
                        <option value="Female">Female</option>
                        <option value="Male">Male</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Phone Number <span class="text-red-500">*</span></label>
                    <input type="tel" name="phone" required placeholder="(555) 000-0000" class="form-input">
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" placeholder="patient@example.com" class="form-input">
                </div>

                <div class="form-group md:col-span-2">
                    <label class="form-label">Residential Address</label>
                    <input type="text" name="address" placeholder="Street Address, City, State, ZIP" class="form-input">
                </div>
            </div>
        </div>

        <hr class="border-outline-variant/20">

        <!-- Section 2: Emergency Contact & Insurance -->
        <div>
            <h2 class="section-title mb-4">
                <span class="material-symbols-outlined text-blue-600 text-lg">shield</span>
                <span>2. Emergency Contact & Insurance</span>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="form-group">
                    <label class="form-label">Emergency Contact Name</label>
                    <input type="text" name="emergency_contact_name" placeholder="Name" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Relationship</label>
                    <input type="text" name="emergency_contact_relationship" placeholder="e.g. Spouse / Parent" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Emergency Phone</label>
                    <input type="tel" name="emergency_contact_phone" placeholder="(555) 000-0000" class="form-input">
                </div>

                <div class="form-group">
                    <label class="form-label">Insurance Provider</label>
                    <input type="text" name="insurance_provider" placeholder="e.g. Blue Cross" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Policy Number</label>
                    <input type="text" name="insurance_policy_number" placeholder="Policy #" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Group Number</label>
                    <input type="text" name="insurance_group_number" placeholder="Group #" class="form-input">
                </div>
            </div>
        </div>

        <hr class="border-slate-200">

        <!-- Section 3: Clinical Background & Allergies -->
        <div>
            <h2 class="section-title mb-4">
                <span class="material-symbols-outlined text-blue-600 text-lg">medical_information</span>
                <span>3. Medical Profile & Allergies</span>
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="form-group">
                    <label class="form-label">Known Allergies</label>
                    <input type="text" name="known_allergies" placeholder="e.g. Penicillin, Latex (or None)" class="form-input">
                </div>

                <div class="form-group">
                    <label class="form-label">Blood Group</label>
                    <select name="blood_group" class="form-select">
                        <option value="O+">O+</option>
                        <option value="O-">O-</option>
                        <option value="A+">A+</option>
                        <option value="A-">A-</option>
                        <option value="B+">B+</option>
                        <option value="B-">B-</option>
                        <option value="AB+">AB+</option>
                        <option value="AB-">AB-</option>
                    </select>
                </div>

                <div class="form-group md:col-span-2">
                    <label class="form-label">Chronic Conditions & Baseline Notes</label>
                    <textarea name="chronic_conditions" rows="3" placeholder="Document any existing medical conditions..." class="form-textarea"></textarea>
                </div>
            </div>
        </div>

        <!-- Submit Actions -->
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-200">
            <a href="patients.php" class="btn-secondary">
                Cancel
            </a>
            <button type="submit" class="btn-primary">
                <span class="material-symbols-outlined text-base">how_to_reg</span>
                <span>Register & Open Chart</span>
            </button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
