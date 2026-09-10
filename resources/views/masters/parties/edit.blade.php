<x-masters.form action="{{ route('masters.parties.update', $party) }}" :isEdit="true">
    <h2>Edit Party: {{ $party->business_name }}</h2>
    <div class="filters">
        <div>
            <label>Category *</label>
            <select name="classification" required>
                <option value="">--Select Category--</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat }}" @selected(old('classification', $party->classification) === $cat)>{{ $cat }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Business Name *</label>
            <input type="text" name="business_name" value="{{ old('business_name', $party->business_name) }}" maxlength="255" required>
        </div>
        <div><label>Contact</label><input type="text" name="contact" value="{{ old('contact', $party->contact) }}" maxlength="100"></div>
        <div><label>Address</label><input type="text" name="address" value="{{ old('address', $party->address) }}"></div>
        <div><label>City</label><input type="text" name="city" value="{{ old('city', $party->city) }}" maxlength="100"></div>
        <div><label>Country *</label>
            <input type="text" name="country" value="{{ old('country', $party->country) }}" maxlength="200" required>
        </div>
        <div><label>State</label><input type="text" name="state" value="{{ old('state', $party->state) }}" maxlength="100"></div>
        <div><label>PIN</label><input type="number" name="pin" value="{{ old('pin', $party->pin) }}"></div>
        <div><label>Mobile</label><input type="number" name="mob" value="{{ old('mob', $party->mob) }}"></div>
        <div><label>STD</label><input type="number" name="std" value="{{ old('std', $party->std) }}"></div>
        <div><label>Phone</label><input type="text" name="phone" value="{{ old('phone', $party->phone) }}" maxlength="20"></div>
        <div><label>TIN</label><input type="text" name="tin" value="{{ old('tin', $party->tin) }}" maxlength="100"></div>
        <div><label>CST</label><input type="text" name="cst" value="{{ old('cst', $party->cst) }}" maxlength="100"></div>
        <div><label>PAN</label><input type="text" name="pan" value="{{ old('pan', $party->pan) }}" maxlength="100"></div>
        <div><label>Product</label><input type="text" name="product" value="{{ old('product', $party->product) }}" maxlength="100"></div>
    </div>
</x-masters.form>
